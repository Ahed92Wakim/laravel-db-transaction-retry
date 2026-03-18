<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DatabaseTransactions\RetryHelper\Writers\RequestLogWriter;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

class RequestMonitor
{
    private bool $enabled;
    private string $logTable;
    private string $queryTable;
    private ?array $context        = null;
    private ?array $pendingCommand = null;
    private bool $isPersisting     = false;
    /** @var list<string> */
    private array $ignoreTables = [];
    private RequestLogWriter $writer;

    public function __construct(array $config)
    {
        $this->enabled    = ! RetryToggle::isExplicitlyDisabledValue($config['enabled'] ?? true);
        $this->logTable   = trim((string) ($config['log_table'] ?? 'db_request_logs'));
        $this->queryTable = trim((string) ($config['query_table'] ?? 'db_query_logs'));
        $logConnection    = isset($config['log_connection']) && $config['log_connection'] !== ''
            ? (string) $config['log_connection']
            : null;

        $this->ignoreTables = $this->resolveIgnoreTables();
        $this->writer       = new RequestLogWriter($this->logTable, $this->queryTable, $logConnection);
    }

    public function handleCommandStarting(CommandStarting $event): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->shouldIgnoreCommand($event->command ?? '')) {
            return;
        }

        $startMicro = microtime(true);

        $this->pendingCommand = [
            'command'         => $event->command,
            'start_microtime' => $startMicro,
            'started_at'      => TimeHelper::timestampFromMicrotime($startMicro),
        ];
    }

    public function handleCommandFinished(CommandFinished $event): void
    {
        if (! $this->enabled) {
            return;
        }

        if (($this->context['type'] ?? null) === 'command') {
            $this->finalizeContext(null);
        }

        $this->pendingCommand = null;
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        if (! $this->enabled || $this->isPersisting) {
            return;
        }

        if ($this->logTable === '' || $this->queryTable === '') {
            return;
        }

        if ($this->shouldIgnoreQuery((string) ($event->sql ?? ''))) {
            return;
        }

        if ($this->context === null) {
            $this->context = $this->buildContext();
        }

        if ($this->context === null) {
            return;
        }

        $queryCount                   = (int) ($this->context['query_count'] ?? 0) + 1;
        $this->context['query_count'] = $queryCount;

        $timeMs   = is_numeric($event->time) ? (int) round($event->time) : 0;
        $rawSql   = $this->resolveRawSql($event);
        $sqlQuery = $event->sql;
        $bindings = $event->bindings ?? [];

        $this->context['queries'][] = [
            'raw_sql'         => $rawSql,
            'sql_query'       => $sqlQuery,
            'bindings'        => $bindings,
            'time_ms'         => $timeMs,
            'order'           => $queryCount,
            'connection_name' => $event->connectionName ?? 'default',
        ];
    }

    public function handleRequestHandled(RequestHandled $event): void
    {
        if (! $this->enabled) {
            return;
        }

        if (($this->context['type'] ?? null) !== 'http') {
            return;
        }

        $status = null;
        try {
            $response = $event->response ?? null;
            $status   = $response && method_exists($response, 'getStatusCode')
                ? $response->getStatusCode()
                : null;
        } catch (Throwable) {
            $status = null;
        }

        $this->finalizeContext(is_int($status) ? $status : null);
    }

    private function buildContext(): ?array
    {
        if ($this->pendingCommand !== null) {
            return $this->commandContext($this->pendingCommand);
        }

        return $this->httpContext();
    }

    private function commandContext(array $pendingCommand): array
    {
        $startMicro = is_numeric($pendingCommand['start_microtime'] ?? null)
            ? (float) $pendingCommand['start_microtime']
            : microtime(true);
        $startedAt = is_string($pendingCommand['started_at'] ?? null)
            ? $pendingCommand['started_at']
            : TimeHelper::timestampFromMicrotime($startMicro);

        $requestContext = RequestContext::snapshot();

        return [
            'type'            => 'command',
            'started_at'      => $startedAt,
            'start_microtime' => $startMicro,
            'query_count'     => 0,
            'queries'         => [],
            'route_name'      => $pendingCommand['command'] ?? null,
            'http_method'     => 'CLI',
            'url'             => $pendingCommand['command'] ?? null,
            'ip_address'      => null,
            'user_id'         => $requestContext['user_id'] ?? null,
        ];
    }

    private function httpContext(): ?array
    {
        if (! function_exists('request')) {
            return null;
        }

        try {
            $request = request();
        } catch (Throwable) {
            return null;
        }

        if (! $request) {
            return null;
        }

        if ($this->shouldIgnoreRequest($request)) {
            return null;
        }

        $startMicro     = $this->resolveRequestStart($request);
        $startedAt      = TimeHelper::timestampFromMicrotime($startMicro);
        $requestContext = RequestContext::snapshot();

        return [
            'type'            => 'http',
            'started_at'      => $startedAt,
            'start_microtime' => $startMicro,
            'query_count'     => 0,
            'queries'         => [],
            'route_name'      => $requestContext['route_name'],
            'http_method'     => $requestContext['method'],
            'url'             => $requestContext['url'],
            'ip_address'      => $requestContext['ip_address'],
            'user_id'         => $requestContext['user_id'],
        ];
    }

    private function finalizeContext(?int $httpStatus): void
    {
        $context       = $this->context;
        $this->context = null;

        if (! is_array($context)) {
            return;
        }

        $queries = is_array($context['queries'] ?? null) ? $context['queries'] : [];
        if ($queries === []) {
            return;
        }

        $completedAt = TimeHelper::nowTimestamp();
        $elapsedMs   = TimeHelper::calculateElapsedMs($context);

        $row = [
            'started_at'          => $context['started_at'] ?? $completedAt,
            'completed_at'        => $completedAt,
            'elapsed_ms'          => $elapsedMs,
            'total_queries_count' => (int) ($context['query_count'] ?? count($queries)),
            'user_id'             => $context['user_id']     ?? null,
            'route_name'          => $context['route_name']  ?? null,
            'http_method'         => $context['http_method'] ?? null,
            'url'                 => $context['url']         ?? null,
            'ip_address'          => $context['ip_address']  ?? null,
            'http_status'         => $httpStatus,
        ];

        $this->isPersisting = true;

        try {
            $this->writer->writeRequestLogAndQueries($row, $queries);
        } finally {
            $this->isPersisting = false;
        }
    }

    private function resolveRawSql(QueryExecuted $event): string
    {
        try {
            $raw = $event->toRawSql();

            return is_string($raw) ? $raw : $event->sql;
        } catch (Throwable) {
            return $event->sql;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveIgnoreTables(): array
    {
        $tables = [
            $this->logTable,
            $this->queryTable,
            'transaction_retry_events',
            'db_transaction_logs',
            'db_query_logs',
            'db_exceptions',
            'db_request_logs',
        ];

        if (function_exists('config')) {
            $tables[] = (string) config('database-transaction-retry.logging.table', 'transaction_retry_events');
            $tables[] = (string) config('database-transaction-retry.slow_transactions.log_table', 'db_transaction_logs');
            $tables[] = (string) config('database-transaction-retry.slow_transactions.query_table', 'db_query_logs');
            $tables[] = (string) config('database-transaction-retry.exception_logging.table', 'db_exceptions');
            $tables[] = (string) config('database-transaction-retry.request_logging.log_table', 'db_request_logs');
            $tables[] = (string) config('database-transaction-retry.request_logging.query_table', 'db_query_logs');
        }

        $normalized = [];
        foreach ($tables as $table) {
            $table = strtolower(trim((string) $table));
            if ($table !== '') {
                $normalized[] = $table;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function shouldIgnoreQuery(string $sql): bool
    {
        $sql = strtolower($sql);

        if ($sql === '' || $this->ignoreTables === []) {
            return false;
        }

        foreach ($this->ignoreTables as $table) {
            $pattern = '/(^|[^a-z0-9_])' . preg_quote($table, '/') . '([^a-z0-9_]|$)/';
            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    private function resolveRequestStart(mixed $request): float
    {
        try {
            if (method_exists($request, 'server')) {
                $start = $request->server('REQUEST_TIME_FLOAT');
                if (is_numeric($start)) {
                    return (float) $start;
                }

                $start = $request->server('REQUEST_TIME');
                if (is_numeric($start)) {
                    return (float) $start;
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return microtime(true);
    }

    private function shouldIgnoreRequest(mixed $request): bool
    {
        $path = null;

        try {
            if (method_exists($request, 'path')) {
                $path = $request->path();
            } elseif (method_exists($request, 'getPathInfo')) {
                $path = $request->getPathInfo();
            } elseif (method_exists($request, 'server')) {
                $path = $request->server('REQUEST_URI');
            }
        } catch (Throwable) {
            $path = null;
        }

        if (! is_string($path)) {
            return false;
        }

        $path = trim($path, '/');
        if ($path === '') {
            return false;
        }

        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }

        $dashboardPath = DashboardAssets::dashboardPath();
        if ($dashboardPath !== '' && ($path === $dashboardPath || str_starts_with($path, $dashboardPath . '/'))) {
            return true;
        }

        $apiPrefix = function_exists('config')
            ? trim((string) config('database-transaction-retry.api.prefix', 'api/transaction-retry'), '/')
            : 'api/transaction-retry';

        if ($apiPrefix !== '' && ($path === $apiPrefix || str_starts_with($path, $apiPrefix . '/'))) {
            return true;
        }

        return false;
    }

    private function shouldIgnoreCommand(string $command): bool
    {
        $command = trim($command);

        if ($command === '') {
            return false;
        }

        return str_starts_with($command, 'db-transaction-retry:');
    }
}

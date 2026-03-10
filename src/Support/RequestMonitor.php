<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DateTimeImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Throwable;

class RequestMonitor
{
    private bool $enabled;
    private string $logTable;
    private string $queryTable;
    private ?string $logConnection;
    private ?array $context = null;
    private ?array $pendingCommand = null;
    private bool $isPersisting = false;
    /** @var list<string> */
    private array $ignoreTables = [];

    public function __construct(array $config)
    {
        $this->enabled = ! RetryToggle::isExplicitlyDisabledValue($config['enabled'] ?? true);
        $this->logTable = trim((string) ($config['log_table'] ?? 'db_request_logs'));
        $this->queryTable = trim((string) ($config['query_table'] ?? 'db_query_logs'));
        $this->logConnection = isset($config['log_connection']) && $config['log_connection'] !== ''
            ? (string) $config['log_connection']
            : null;
        $this->ignoreTables = $this->resolveIgnoreTables();
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
            'command' => $event->command,
            'start_microtime' => $startMicro,
            'started_at' => $this->timestampFromMicrotime($startMicro),
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

        $queryCount = (int) ($this->context['query_count'] ?? 0) + 1;
        $this->context['query_count'] = $queryCount;

        $timeMs   = is_numeric($event->time) ? (int) round($event->time) : 0;
        $rawSql   = $this->resolveRawSql($event);
        $sqlQuery = $event->sql;
        $bindings = $event->bindings ?? [];

        $this->context['queries'][] = [
            'raw_sql' => $rawSql,
            'sql_query' => $sqlQuery,
            'bindings' => $bindings,
            'time_ms' => $timeMs,
            'order' => $queryCount,
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
            $status = $response && method_exists($response, 'getStatusCode')
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
            : $this->timestampFromMicrotime($startMicro);

        return [
            'type' => 'command',
            'started_at' => $startedAt,
            'start_microtime' => $startMicro,
            'query_count' => 0,
            'queries' => [],
            'route_name' => $pendingCommand['command'] ?? null,
            'http_method' => 'CLI',
            'url' => $pendingCommand['command'] ?? null,
            'ip_address' => null,
            'user_id' => $this->resolveUserId(),
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

        $startMicro = $this->resolveRequestStart($request);
        $startedAt  = $this->timestampFromMicrotime($startMicro);
        $routeName  = null;
        $url        = null;

        try {
            $route = method_exists($request, 'route') ? $request->route() : null;
            if (is_object($route) && method_exists($route, 'getName')) {
                $routeName = $route->getName();
            } elseif (is_string($route)) {
                $routeName = $route;
            }
            if (is_object($route) && method_exists($route, 'uri')) {
                $url = $route->uri();
            }
        } catch (Throwable) {
            $routeName = null;
            $url = null;
        }

        return [
            'type' => 'http',
            'started_at' => $startedAt,
            'start_microtime' => $startMicro,
            'query_count' => 0,
            'queries' => [],
            'route_name' => $routeName,
            'http_method' => method_exists($request, 'getMethod') ? $request->getMethod() : null,
            'url' => $url,
            'ip_address' => method_exists($request, 'ip') ? $request->ip() : null,
            'user_id' => $this->resolveUserId(),
        ];
    }

    private function finalizeContext(?int $httpStatus): void
    {
        $context = $this->context;
        $this->context = null;

        if (! is_array($context)) {
            return;
        }

        $queries = is_array($context['queries'] ?? null) ? $context['queries'] : [];
        if ($queries === []) {
            return;
        }

        $completedAt = $this->nowTimestamp();
        $elapsedMs = $this->calculateElapsedMs($context);

        $row = [
            'started_at' => $context['started_at'] ?? $completedAt,
            'completed_at' => $completedAt,
            'elapsed_ms' => $elapsedMs,
            'total_queries_count' => (int) ($context['query_count'] ?? count($queries)),
            'user_id' => $context['user_id'] ?? null,
            'route_name' => $context['route_name'] ?? null,
            'http_method' => $context['http_method'] ?? null,
            'url' => $context['url'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'http_status' => $httpStatus,
        ];

        $this->isPersisting = true;

        try {
            if (! class_exists(DB::class)) {
                return;
            }

            $db = $this->logConnection ? DB::connection($this->logConnection) : DB::connection();

            $logId = $db->table($this->logTable)->insertGetId($row);

            if (! is_numeric($logId)) {
                return;
            }

            $this->writeQueryLogs((int) $logId, $queries);
        } catch (Throwable) {
            // Never interrupt the application flow if logging fails.
        } finally {
            $this->isPersisting = false;
        }
    }

    private function writeQueryLogs(int $logId, array $queries): void
    {
        if ($this->queryTable === '') {
            return;
        }

        $rows = [];
        foreach ($queries as $query) {
            $rows[] = [
                'loggable_id' => $logId,
                'loggable_type' => $this->logTable,
                'raw_sql' => (string) ($query['raw_sql'] ?? $query['sql'] ?? ''),
                'sql_query' => (string) ($query['sql_query'] ?? $query['sql'] ?? ''),
                'bindings' => $this->encodeJson($query['bindings'] ?? null),
                'execution_time_ms' => (int) ($query['time_ms'] ?? 0),
                'connection_name' => (string) ($query['connection_name'] ?? ''),
                'query_order' => (int) ($query['order'] ?? 0),
            ];
        }

        if ($rows === []) {
            return;
        }

        $db = $this->logConnection ? DB::connection($this->logConnection) : DB::connection();
        $db->table($this->queryTable)->insert($rows);
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

    private function calculateElapsedMs(array $context): int
    {
        $startMicro = (float) ($context['start_microtime'] ?? microtime(true));

        return (int) round((microtime(true) - $startMicro) * 1000);
    }

    private function nowTimestamp(): string
    {
        if (function_exists('now')) {
            return now()->format('Y-m-d H:i:s.v');
        }

        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
    }

    private function timestampFromMicrotime(float $microtime): string
    {
        $formatted = sprintf('%.6F', $microtime);
        $date = DateTimeImmutable::createFromFormat('U.u', $formatted);

        if ($date === false) {
            return $this->nowTimestamp();
        }

        return $date->format('Y-m-d H:i:s.v');
    }

    private function resolveUserId(): ?int
    {
        if (! function_exists('auth')) {
            return null;
        }

        try {
            $guard = auth();
            $user  = $guard->user();

            if (! $user) {
                return null;
            }

            $id = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : ($user->id ?? null);

            return is_numeric($id) ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function encodeJson(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}

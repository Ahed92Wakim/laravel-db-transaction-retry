<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DatabaseTransactions\RetryHelper\Writers\SlowTransactionWriter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlowTransactionMonitor
{
    private array $transactionStacks = [];
    private int $transactionThresholdMs;
    private int $slowQueryThresholdMs;
    private bool $logEnabled;
    private ?string $logChannel;
    private ?int $lastResponseStatus = null;
    private array $pendingLogIds     = [];
    private SlowTransactionWriter $writer;

    public function __construct(array $config)
    {
        $this->transactionThresholdMs = max(0, (int) ($config['transaction_threshold_ms'] ?? 2000));
        $this->slowQueryThresholdMs   = max(0, (int) ($config['slow_query_threshold_ms'] ?? 1000));
        $this->logEnabled = (bool) ($config['log_enabled'] ?? true);
        $this->logChannel = isset($config['log_channel']) && $config['log_channel'] !== ''
            ? (string) $config['log_channel']
            : null;

        $logTable      = trim((string) ($config['log_table'] ?? 'db_transaction_logs'));
        $queryTable    = trim((string) ($config['query_table'] ?? 'db_query_logs'));
        $logConnection = isset($config['log_connection']) && $config['log_connection'] !== ''
            ? (string) $config['log_connection']
            : null;

        $this->writer = new SlowTransactionWriter($logTable, $queryTable, $logConnection);
    }

    public function handleTransactionBeginning(TransactionBeginning $event): void
    {
        $connection = $event->connectionName                ?? 'default';
        $stack      = $this->transactionStacks[$connection] ?? [];
        $isRoot     = count($stack) === 0;

        $context = [
            'start_hrtime'    => function_exists('hrtime') ? hrtime(true) : null,
            'start_microtime' => microtime(true),
            'started_at'      => TimeHelper::nowTimestamp(),
            'is_root'         => $isRoot,
        ];

        if ($isRoot) {
            $context['queries']           = [];
            $context['query_count']       = 0;
            $context['last_query']        = null;
            $context['transaction_label'] = $this->resolveTransactionLabel();
        }

        $stack[]                              = $context;
        $this->transactionStacks[$connection] = $stack;
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        $connection = $event->connectionName ?? 'default';
        if (empty($this->transactionStacks[$connection])) {
            return;
        }

        $rootIndex = 0;
        if (! isset($this->transactionStacks[$connection][$rootIndex])) {
            return;
        }

        $root = &$this->transactionStacks[$connection][$rootIndex];

        if (! isset($root['queries'])) {
            $root['queries']     = [];
            $root['query_count'] = 0;
        }

        $root['query_count'] = (int) ($root['query_count'] ?? 0) + 1;
        $order               = $root['query_count'];

        $timeMs   = is_numeric($event->time) ? (int) round($event->time) : 0;
        $rawSql   = $this->resolveRawSql($event);
        $sqlQuery = $event->sql;
        $bindings = $event->bindings ?? [];

        $entry = [
            'raw_sql'         => $rawSql,
            'sql_query'       => $sqlQuery,
            'bindings'        => $bindings,
            'time_ms'         => $timeMs,
            'order'           => $order,
            'connection_name' => $connection,
        ];

        $root['queries'][]  = $entry;
        $root['last_query'] = $entry;
    }

    public function handleTransactionCommitted(TransactionCommitted $event): void
    {
        $this->finalizeTransaction($event->connectionName ?? 'default', 'committed');
    }

    public function handleTransactionRolledBack(TransactionRolledBack $event): void
    {
        $this->finalizeTransaction($event->connectionName ?? 'default', 'rolled_back');
    }

    public function handleRequestHandled(RequestHandled $event): void
    {
        $status = null;

        try {
            $response = $event->response ?? null;
            $status   = $response && method_exists($response, 'getStatusCode')
                ? $response->getStatusCode()
                : null;
        } catch (Throwable) {
            $status = null;
        }

        if (! is_int($status)) {
            $this->pendingLogIds      = [];
            $this->lastResponseStatus = null;

            return;
        }

        $this->lastResponseStatus = $status;

        if ($this->pendingLogIds === []) {
            return;
        }

        $this->writer->updateHttpStatus($this->pendingLogIds, $status);

        $this->pendingLogIds      = [];
        $this->lastResponseStatus = null;
    }

    private function finalizeTransaction(string $connection, string $status): void
    {
        if (empty($this->transactionStacks[$connection])) {
            return;
        }

        $context = array_pop($this->transactionStacks[$connection]);

        if (! empty($this->transactionStacks[$connection])) {
            return;
        }
        unset($this->transactionStacks[$connection]);

        $elapsedMs            = TimeHelper::calculateElapsedMs($context);
        $shouldLogTransaction = $this->transactionThresholdMs <= 0 || $elapsedMs >= $this->transactionThresholdMs;
        if (! $shouldLogTransaction) {
            return;
        }

        $queries      = is_array($context['queries'] ?? null) ? $context['queries'] : [];
        $totalQueries = (int) ($context['query_count'] ?? count($queries));
        if ($this->slowQueryThresholdMs <= 0) {
            $slowQueries = $queries;
        } else {
            $slowQueries = array_values(array_filter(
                $queries,
                fn (array $query): bool => (int) ($query['time_ms'] ?? 0) > $this->slowQueryThresholdMs
            ));
        }
        $slowQueriesCount = count($slowQueries);

        $completedAt = TimeHelper::nowTimestamp();
        $startedAt   = $context['started_at'] ?? $completedAt;

        $requestContext = RequestContext::snapshot();
        $httpStatus  = is_int($this->lastResponseStatus) ? $this->lastResponseStatus : null;

        $logEntry = $this->writer->writeTransactionLog([
            'transaction_label'   => $context['transaction_label'] ?? null,
            'connection_name'     => $connection,
            'status'              => $status,
            'elapsed_ms'          => $elapsedMs,
            'started_at'          => $startedAt,
            'completed_at'        => $completedAt,
            'total_queries_count' => $totalQueries,
            'slow_queries_count'  => $slowQueriesCount,
            'user_id'             => $requestContext['user_id'],
            'route_name'          => $requestContext['route_name'],
            'http_method'         => $requestContext['method'],
            'url'                 => $requestContext['url'],
            'ip_address'          => $requestContext['ip_address'],
            'http_status'         => $httpStatus,
        ]);

        if (! is_null($logEntry) && $httpStatus === null) {
            $this->pendingLogIds[] = (int) $logEntry['id'];
        }

        if (! is_null($logEntry) && $slowQueriesCount > 0) {
            $this->writer->writeSlowQueries($logEntry, $slowQueries);
        }

        if ($this->logEnabled) {
            $payload = [
                'transaction_label'  => $context['transaction_label'] ?? null,
                'connection'         => $connection,
                'status'             => $status,
                'elapsed_ms'         => $elapsedMs,
                'elapsed_seconds'    => round($elapsedMs / 1000, 3),
                'total_queries'      => $totalQueries,
                'slow_queries_count' => $slowQueriesCount,
                'route_name'         => $requestContext['route_name'],
                'method'             => $requestContext['method'],
                'url'                => $requestContext['url'],
                'ip_address'         => $requestContext['ip_address'],
                'http_status'        => $httpStatus,
                'user_id'            => $requestContext['user_id'],
                'slow_queries'       => $this->sortedSlowQueries($slowQueries),
            ];

            if ($status === 'rolled_back') {
                $payload['last_query'] = $context['last_query'] ?? null;
            }

            $this->logMessage($status, $payload);
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

    private function resolveTransactionLabel(): ?string
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            if (! $app || ! $app->bound('tx.label')) {
                return null;
            }

            $label = trim((string) $app->make('tx.label'));

            return $label !== '' ? $label : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sortedSlowQueries(array $slowQueries): array
    {
        usort($slowQueries, static function (array $left, array $right): int {
            return (int) ($right['time_ms'] ?? 0) <=> (int) ($left['time_ms'] ?? 0);
        });

        return $slowQueries;
    }

    private function logMessage(string $status, array $payload): void
    {
        if (! class_exists(Log::class)) {
            return;
        }

        $level   = $status === 'rolled_back' ? 'error' : 'warning';
        $message = $this->formatMessage($status, $payload);

        try {
            if ($this->logChannel) {
                Log::channel($this->logChannel)->log($level, $message, $payload);
            } else {
                Log::log($level, $message, $payload);
            }
        } catch (Throwable) {
            // Avoid breaking the request flow if logging fails.
        }
    }

    private function formatMessage(string $status, array $payload): string
    {
        $elapsedMs = (int) ($payload['elapsed_ms'] ?? 0);
        $queries   = (int) ($payload['total_queries'] ?? 0);
        $url       = (string) ($payload['url'] ?? '');

        $suffix = $url !== '' ? ' ' . $url : '';

        return sprintf(
            'Slow database transaction %s (%dms, %d queries)%s',
            $status === 'rolled_back' ? 'rolled back' : 'committed',
            $elapsedMs,
            $queries,
            $suffix
        );
    }
}

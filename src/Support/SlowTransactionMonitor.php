<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SlowTransactionMonitor
{
    private array $transactionStacks = [];
    private int $transactionThresholdMs;
    private int $slowQueryThresholdMs;
    private string $logTable;
    private string $queryTable;
    private ?string $logConnection;
    private bool $logEnabled;
    private ?string $logChannel;
    private ?bool $queryTableHasLogCompletedAt = null;

    public function __construct(array $config)
    {
        $this->transactionThresholdMs = max(0, (int) ($config['transaction_threshold_ms'] ?? 2000));
        $this->slowQueryThresholdMs   = max(0, (int) ($config['slow_query_threshold_ms'] ?? 1000));
        $this->logTable               = trim((string) ($config['log_table'] ?? 'db_transaction_logs'));
        $this->queryTable             = trim((string) ($config['query_table'] ?? 'db_transaction_queries'));
        $this->logConnection          = isset($config['log_connection']) && $config['log_connection'] !== ''
            ? (string) $config['log_connection']
            : null;
        $this->logEnabled = (bool) ($config['log_enabled'] ?? true);
        $this->logChannel = isset($config['log_channel']) && $config['log_channel'] !== ''
            ? (string) $config['log_channel']
            : null;
    }

    public function handleTransactionBeginning(TransactionBeginning $event): void
    {
        $connection = $event->connectionName                ?? 'default';
        $stack      = $this->transactionStacks[$connection] ?? [];
        $isRoot     = count($stack) === 0;

        $context = [
            'start_hrtime'    => $this->startHrtime(),
            'start_microtime' => microtime(true),
            'started_at'      => $this->nowTimestamp(),
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

        $timeMs = is_numeric($event->time) ? (int) round($event->time) : 0;
        $sql    = $this->resolveRawSql($event);

        $entry = [
            'sql'             => $sql,
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

        $elapsedMs            = $this->calculateElapsedMs($context);
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

        $completedAt = $this->nowTimestamp();
        $startedAt   = $context['started_at'] ?? $completedAt;

        $httpContext = $this->resolveHttpContext();
        $userId      = $this->resolveUserId();

        $logEntry = $this->writeTransactionLog([
            'transaction_label'   => $context['transaction_label'] ?? null,
            'connection_name'     => $connection,
            'status'              => $status,
            'elapsed_ms'          => $elapsedMs,
            'started_at'          => $startedAt,
            'completed_at'        => $completedAt,
            'total_queries_count' => $totalQueries,
            'slow_queries_count'  => $slowQueriesCount,
            'user_id'             => $userId,
            'route_name'          => $httpContext['route_name'] ?? null,
            'http_method'         => $httpContext['method']     ?? null,
            'url'                 => $httpContext['url']        ?? null,
            'ip_address'          => $httpContext['ip']         ?? null,
        ]);

        if (! is_null($logEntry) && $slowQueriesCount > 0) {
            $this->writeSlowQueries($logEntry, $slowQueries);
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
                'route_name'         => $httpContext['route_name'] ?? null,
                'method'             => $httpContext['method']     ?? null,
                'url'                => $httpContext['url']        ?? null,
                'ip_address'         => $httpContext['ip']         ?? null,
                'user_id'            => $userId,
                'slow_queries'       => $this->sortedSlowQueries($slowQueries),
            ];

            if ($status === 'rolled_back') {
                $payload['last_query'] = $context['last_query'] ?? null;
            }

            $this->logMessage($status, $payload);
        }
    }

    private function startHrtime(): ?int
    {
        if (! function_exists('hrtime')) {
            return null;
        }

        return hrtime(true);
    }

    private function calculateElapsedMs(array $context): int
    {
        $startHrtime = $context['start_hrtime'] ?? null;
        if (! is_null($startHrtime) && function_exists('hrtime')) {
            $elapsedNs = hrtime(true) - (int) $startHrtime;

            return (int) round($elapsedNs / 1_000_000);
        }

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

    private function resolveHttpContext(): array
    {
        if (! function_exists('request')) {
            return [];
        }

        try {
            $request = request();
        } catch (Throwable) {
            return [];
        }

        if (! $request) {
            return [];
        }

        $routeName = null;
        try {
            $route = $request->route();
            if ($route && method_exists($route, 'getName')) {
                $routeName = $route->getName();
            }
        } catch (Throwable) {
            $routeName = null;
        }

        try {
            $url = method_exists($request, 'fullUrl') ? $request->fullUrl() : $request->url();
        } catch (Throwable) {
            $url = null;
        }

        return [
            'method'     => $request->getMethod(),
            'url'        => $url,
            'ip'         => $request->ip(),
            'route_name' => $routeName,
        ];
    }

    private function writeTransactionLog(array $row): ?array
    {
        if (! class_exists(DB::class)) {
            return null;
        }

        if ($this->logTable === '') {
            return null;
        }

        $insert = [
            'transaction_label'   => $row['transaction_label']   ?? null,
            'connection_name'     => $row['connection_name']     ?? null,
            'status'              => $row['status']              ?? 'committed',
            'elapsed_ms'          => $row['elapsed_ms']          ?? 0,
            'started_at'          => $row['started_at']          ?? null,
            'completed_at'        => $row['completed_at']        ?? null,
            'total_queries_count' => $row['total_queries_count'] ?? 0,
            'slow_queries_count'  => $row['slow_queries_count']  ?? 0,
            'user_id'             => $row['user_id']             ?? null,
            'route_name'          => $row['route_name']          ?? null,
            'http_method'         => $row['http_method']         ?? null,
            'url'                 => $row['url']                 ?? null,
            'ip_address'          => $row['ip_address']          ?? null,
        ];

        try {
            $db = $this->logConnection ? DB::connection($this->logConnection) : DB::connection();

            $id = $db->table($this->logTable)->insertGetId($insert);

            if (! is_numeric($id)) {
                return null;
            }

            return [
                'id'           => (int) $id,
                'completed_at' => $insert['completed_at'] ?? null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function writeSlowQueries(array $logEntry, array $slowQueries): void
    {
        if (! class_exists(DB::class)) {
            return;
        }

        if ($this->queryTable === '') {
            return;
        }

        $logId = $logEntry['id'] ?? null;
        if (! is_numeric($logId)) {
            return;
        }
        $logId = (int) $logId;

        $includeCompletedAt = $this->queryTableHasLogCompletedAt();

        $rows = [];
        foreach ($slowQueries as $query) {
            $row = [
                'transaction_log_id' => $logId,
                'sql_query'          => (string) ($query['sql'] ?? ''),
                'execution_time_ms'  => (int) ($query['time_ms'] ?? 0),
                'connection_name'    => (string) ($query['connection_name'] ?? ''),
                'query_order'        => (int) ($query['order'] ?? 0),
            ];

            if ($includeCompletedAt) {
                $row['transaction_log_completed_at'] = $logEntry['completed_at'] ?? null;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            return;
        }

        try {
            $db = $this->logConnection ? DB::connection($this->logConnection) : DB::connection();
            $db->table($this->queryTable)->insert($rows);
        } catch (Throwable) {
            // Never interrupt the application flow if logging fails.
        }
    }

    private function queryTableHasLogCompletedAt(): bool
    {
        if (! is_null($this->queryTableHasLogCompletedAt)) {
            return $this->queryTableHasLogCompletedAt;
        }

        if ($this->queryTable === '' || ! class_exists(Schema::class)) {
            $this->queryTableHasLogCompletedAt = false;

            return false;
        }

        try {
            $schema                            = $this->logConnection ? Schema::connection($this->logConnection) : Schema::connection();
            $this->queryTableHasLogCompletedAt = $schema->hasColumn($this->queryTable, 'transaction_log_completed_at');
        } catch (Throwable) {
            $this->queryTableHasLogCompletedAt = false;
        }

        return $this->queryTableHasLogCompletedAt;
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

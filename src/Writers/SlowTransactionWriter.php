<?php

namespace DatabaseTransactions\RetryHelper\Writers;

use DatabaseTransactions\RetryHelper\Models\QueryLog;
use DatabaseTransactions\RetryHelper\Models\SlowTransactionLog;
use DatabaseTransactions\RetryHelper\Support\SerializationHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SlowTransactionWriter
{
    private string $logTable;
    private string $queryTable;
    private ?string $logConnection;
    private ?bool $logTableHasHttpStatus       = null;
    private ?bool $queryTableHasLogCompletedAt = null;

    public function __construct(string $logTable, string $queryTable, ?string $logConnection = null)
    {
        $this->logTable      = $logTable;
        $this->queryTable    = $queryTable;
        $this->logConnection = $logConnection;
    }

    public function writeTransactionLog(array $row): ?array
    {
        if (! class_exists(DB::class)) {
            return null;
        }

        if ($this->logTable === '') {
            return null;
        }

        $logModel = SlowTransactionLog::instance($this->logTable, $this->logConnection);

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

        if ($this->logTableHasHttpStatus()) {
            $insert['http_status'] = $row['http_status'] ?? null;
        }

        try {
            $db = $logModel->getConnectionName() ? DB::connection($logModel->getConnectionName()) : DB::connection();

            $id = $db->table($logModel->getTable())->insertGetId($insert);

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

    public function writeSlowQueries(array $logEntry, array $slowQueries): void
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
        $logModel           = SlowTransactionLog::instance($this->logTable, $this->logConnection);
        $queryModel         = QueryLog::instance($this->queryTable, $this->logConnection);

        $rows = [];
        foreach ($slowQueries as $query) {
            $row = [
                'loggable_id'       => $logId,
                'loggable_type'     => $logModel->getTable(),
                'raw_sql'           => (string) ($query['raw_sql'] ?? $query['sql'] ?? ''),
                'sql_query'         => (string) ($query['sql_query'] ?? $query['sql'] ?? ''),
                'bindings'          => SerializationHelper::encodeJson($query['bindings'] ?? null),
                'execution_time_ms' => (int) ($query['time_ms'] ?? 0),
                'connection_name'   => (string) ($query['connection_name'] ?? ''),
                'query_order'       => (int) ($query['order'] ?? 0),
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
            $db = $queryModel->getConnectionName() ? DB::connection($queryModel->getConnectionName()) : DB::connection();
            $db->table($queryModel->getTable())->insert($rows);
        } catch (Throwable) {
            // Never interrupt the application flow if logging fails.
        }
    }

    public function updateHttpStatus(array $logIds, ?int $status): void
    {
        if (empty($logIds) || $status === null) {
            return;
        }

        if (! $this->logTableHasHttpStatus()) {
            return;
        }

        try {
            $logModel = SlowTransactionLog::instance($this->logTable, $this->logConnection);
            $db       = $logModel->getConnectionName() ? DB::connection($logModel->getConnectionName()) : DB::connection();

            $db->table($logModel->getTable())
                ->whereIn('id', $logIds)
                ->update(['http_status' => $status]);
        } catch (Throwable) {
            // ignore
        }
    }

    public function logTableHasHttpStatus(): bool
    {
        if (! is_null($this->logTableHasHttpStatus)) {
            return $this->logTableHasHttpStatus;
        }

        try {
            $logModel                    = SlowTransactionLog::instance($this->logTable, $this->logConnection);
            $db                          = $logModel->getConnectionName() ? DB::connection($logModel->getConnectionName()) : DB::connection();
            $schema                      = $db->getSchemaBuilder();
            $this->logTableHasHttpStatus = $schema->hasColumn($logModel->getTable(), 'http_status');
        } catch (Throwable) {
            $this->logTableHasHttpStatus = false;
        }

        return $this->logTableHasHttpStatus;
    }

    public function queryTableHasLogCompletedAt(): bool
    {
        if (! is_null($this->queryTableHasLogCompletedAt)) {
            return $this->queryTableHasLogCompletedAt;
        }

        if ($this->queryTable === '' || ! class_exists(Schema::class)) {
            $this->queryTableHasLogCompletedAt = false;

            return false;
        }

        try {
            $queryModel                        = QueryLog::instance($this->queryTable, $this->logConnection);
            $schema                            = $queryModel->getConnectionName() ? Schema::connection($queryModel->getConnectionName()) : Schema::connection();
            $this->queryTableHasLogCompletedAt = $schema->hasColumn($queryModel->getTable(), 'transaction_log_completed_at');
        } catch (Throwable) {
            $this->queryTableHasLogCompletedAt = false;
        }

        return $this->queryTableHasLogCompletedAt;
    }
}

<?php

namespace DatabaseTransactions\RetryHelper\Writers;

use DatabaseTransactions\RetryHelper\Support\SerializationHelper;
use Illuminate\Support\Facades\DB;
use Throwable;

class RequestLogWriter
{
    private string $logTable;
    private string $queryTable;
    private ?string $logConnection;

    public function __construct(string $logTable, string $queryTable, ?string $logConnection = null)
    {
        $this->logTable      = $logTable;
        $this->queryTable    = $queryTable;
        $this->logConnection = $logConnection;
    }

    public function writeRequestLogAndQueries(array $row, array $queries): void
    {
        try {
            if (! class_exists(DB::class)) {
                return;
            }

            if ($this->logTable === '') {
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
                'loggable_id'       => $logId,
                'loggable_type'     => $this->logTable,
                'raw_sql'           => (string) ($query['raw_sql'] ?? $query['sql'] ?? ''),
                'sql_query'         => (string) ($query['sql_query'] ?? $query['sql'] ?? ''),
                'bindings'          => SerializationHelper::encodeJson($query['bindings'] ?? null),
                'execution_time_ms' => (int) ($query['time_ms'] ?? 0),
                'connection_name'   => (string) ($query['connection_name'] ?? ''),
                'query_order'       => (int) ($query['order'] ?? 0),
            ];
        }

        if ($rows === []) {
            return;
        }

        $db = $this->logConnection ? DB::connection($this->logConnection) : DB::connection();
        $db->table($this->queryTable)->insert($rows);
    }
}

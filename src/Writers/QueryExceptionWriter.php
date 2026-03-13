<?php

namespace DatabaseTransactions\RetryHelper\Writers;

use Illuminate\Support\Facades\DB;
use Throwable;

class QueryExceptionWriter
{
    private string $logTable;
    private ?string $logConnection;

    public function __construct(string $logTable, ?string $logConnection = null)
    {
        $this->logTable      = $logTable;
        $this->logConnection = $logConnection;
    }

    public function writeExceptionLog(array $row): void
    {
        try {
            if (! class_exists(DB::class)) {
                return;
            }

            if ($this->logTable === '') {
                return;
            }

            $db = $this->logConnection ? DB::connection($this->logConnection) : DB::connection();
            $db->table($this->logTable)->insert($row);
        } catch (Throwable) {
            // Never allow logging failures to interfere with exception handling.
        }
    }
}

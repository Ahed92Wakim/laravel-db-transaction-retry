<?php

namespace DatabaseTransactions\RetryHelper\Models;

class SlowTransactionLog extends PackageModel
{
    public $timestamps = false;

    protected $table = 'db_transaction_logs';

    protected function casts(): array
    {
        return [
            'elapsed_ms'          => 'integer',
            'started_at'          => 'immutable_datetime',
            'completed_at'        => 'immutable_datetime',
            'total_queries_count' => 'integer',
            'slow_queries_count'  => 'integer',
            'user_id'             => 'integer',
            'http_status'         => 'integer',
        ];
    }
}

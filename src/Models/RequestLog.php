<?php

namespace DatabaseTransactions\RetryHelper\Models;

class RequestLog extends PackageModel
{
    public $timestamps = false;

    protected $table = 'db_request_logs';

    protected function casts(): array
    {
        return [
            'started_at'          => 'immutable_datetime',
            'completed_at'        => 'immutable_datetime',
            'elapsed_ms'          => 'integer',
            'total_queries_count' => 'integer',
            'user_id'             => 'integer',
            'http_status'         => 'integer',
        ];
    }
}

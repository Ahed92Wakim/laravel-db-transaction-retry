<?php

namespace DatabaseTransactions\RetryHelper\Models;

class QueryLog extends PackageModel
{
    public $timestamps = false;

    protected $table = 'db_query_logs';

    protected function casts(): array
    {
        return [
            'loggable_id'       => 'integer',
            'bindings'          => 'array',
            'execution_time_ms' => 'integer',
            'query_order'       => 'integer',
        ];
    }
}

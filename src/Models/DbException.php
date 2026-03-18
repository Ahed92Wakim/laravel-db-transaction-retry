<?php

namespace DatabaseTransactions\RetryHelper\Models;

class DbException extends PackageModel
{
    protected $table = 'db_exceptions';

    protected function casts(): array
    {
        return [
            'occurred_at'     => 'immutable_datetime',
            'driver_code'     => 'integer',
            'bindings'        => 'array',
            'error_info'      => 'array',
            'auth_header_len' => 'integer',
            'trace'           => 'array',
            'context'         => 'array',
            'created_at'      => 'immutable_datetime',
            'updated_at'      => 'immutable_datetime',
        ];
    }
}

<?php

namespace DatabaseTransactions\RetryHelper\Models;

use DatabaseTransactions\RetryHelper\Enums\LogLevel;
use DatabaseTransactions\RetryHelper\Enums\RetryStatus;

class TransactionRetryEvent extends PackageModel
{
    protected $table = 'transaction_retry_events';

    protected function casts(): array
    {
        return [
            'occurred_at'     => 'immutable_datetime',
            'retry_status'    => RetryStatus::class,
            'log_level'       => LogLevel::class,
            'attempt'         => 'integer',
            'max_retries'     => 'integer',
            'driver_code'     => 'integer',
            'bindings'        => 'array',
            'error_info'      => 'array',
            'auth_header_len' => 'integer',
            'context'         => 'array',
            'created_at'      => 'immutable_datetime',
            'updated_at'      => 'immutable_datetime',
        ];
    }
}

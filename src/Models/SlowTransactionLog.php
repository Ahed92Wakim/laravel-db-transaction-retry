<?php

namespace DatabaseTransactions\RetryHelper\Models;

/**
 * @property int $id
 * @property string|null $http_method
 * @property string|null $route_name
 * @property string|null $url
 * @property int|null $http_status
 * @property int $elapsed_ms
 * @property int $total_queries_count
 * @property int $slow_queries_count
 * @property \DateTimeImmutable|string|null $started_at
 * @property \DateTimeImmutable|string|null $completed_at
 * @property int|null $user_id
 */
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

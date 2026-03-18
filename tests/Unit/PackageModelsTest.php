<?php

namespace Tests;

use Carbon\CarbonImmutable;
use DatabaseTransactions\RetryHelper\Enums\LogLevel;
use DatabaseTransactions\RetryHelper\Enums\RetryStatus;
use DatabaseTransactions\RetryHelper\Models\DbException;
use DatabaseTransactions\RetryHelper\Models\QueryLog;
use DatabaseTransactions\RetryHelper\Models\RequestLog;
use DatabaseTransactions\RetryHelper\Models\SlowTransactionLog;
use DatabaseTransactions\RetryHelper\Models\TransactionRetryEvent;

test('transaction retry event model resolves configured table and casts attributes', function (): void {
    $model = TransactionRetryEvent::instance('custom_retry_events', 'analytics');
    $model->setRawAttributes([
        'occurred_at'  => '2026-03-16 10:11:12',
        'retry_status' => 'success',
        'log_level'    => 'warning',
        'attempt'      => '2',
        'max_retries'  => '3',
        'bindings'     => '["foo"]',
        'error_info'   => '[40001,1213]',
        'context'      => '{"route":"orders.show"}',
    ], true);

    expect($model->getTable())->toBe('custom_retry_events')
        ->and($model->getConnectionName())->toBe('analytics')
        ->and($model->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($model->retry_status)->toBe(RetryStatus::Success)
        ->and($model->log_level)->toBe(LogLevel::Warning)
        ->and($model->attempt)->toBe(2)
        ->and($model->max_retries)->toBe(3)
        ->and($model->bindings)->toBe(['foo'])
        ->and($model->error_info)->toBe([40001, 1213])
        ->and($model->context)->toBe(['route' => 'orders.show']);
});

test('slow transaction and request log models cast numeric and timestamp attributes', function (): void {
    $slowLog = SlowTransactionLog::instance();
    $slowLog->setRawAttributes([
        'elapsed_ms'          => '125',
        'started_at'          => '2026-03-16 10:00:00',
        'completed_at'        => '2026-03-16 10:00:01',
        'total_queries_count' => '4',
        'slow_queries_count'  => '2',
        'user_id'             => '15',
        'http_status'         => '204',
    ], true);

    $requestLog = RequestLog::instance();
    $requestLog->setRawAttributes([
        'started_at'          => '2026-03-16 11:00:00',
        'completed_at'        => '2026-03-16 11:00:02',
        'elapsed_ms'          => '250',
        'total_queries_count' => '3',
        'user_id'             => '22',
        'http_status'         => '201',
    ], true);

    expect($slowLog->elapsed_ms)->toBe(125)
        ->and($slowLog->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($slowLog->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($slowLog->total_queries_count)->toBe(4)
        ->and($slowLog->slow_queries_count)->toBe(2)
        ->and($slowLog->user_id)->toBe(15)
        ->and($slowLog->http_status)->toBe(204)
        ->and($requestLog->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($requestLog->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($requestLog->elapsed_ms)->toBe(250)
        ->and($requestLog->total_queries_count)->toBe(3)
        ->and($requestLog->user_id)->toBe(22)
        ->and($requestLog->http_status)->toBe(201);
});

test('query log and exception models cast json payloads', function (): void {
    $queryLog = QueryLog::instance();
    $queryLog->setRawAttributes([
        'loggable_id'       => '9',
        'bindings'          => '[1,"two"]',
        'execution_time_ms' => '17',
        'query_order'       => '3',
    ], true);

    $exception = DbException::instance();
    $exception->setRawAttributes([
        'occurred_at'     => '2026-03-16 12:30:00',
        'driver_code'     => '1213',
        'bindings'        => '["baz"]',
        'error_info'      => '["40001",1213,"deadlock"]',
        'auth_header_len' => '64',
        'trace'           => '[{"file":"foo.php","line":10}]',
        'context'         => '{"message":"boom"}',
    ], true);

    expect($queryLog->loggable_id)->toBe(9)
        ->and($queryLog->bindings)->toBe([1, 'two'])
        ->and($queryLog->execution_time_ms)->toBe(17)
        ->and($queryLog->query_order)->toBe(3)
        ->and($exception->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($exception->driver_code)->toBe(1213)
        ->and($exception->bindings)->toBe(['baz'])
        ->and($exception->error_info)->toBe(['40001', 1213, 'deadlock'])
        ->and($exception->auth_header_len)->toBe(64)
        ->and($exception->trace)->toBe([['file' => 'foo.php', 'line' => 10]])
        ->and($exception->context)->toBe(['message' => 'boom']);
});

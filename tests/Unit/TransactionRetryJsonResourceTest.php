<?php

namespace Tests;

use DatabaseTransactions\RetryHelper\Http\Resources\RetryEventsIndexResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryExceptionGroupResource;
use DatabaseTransactions\RetryHelper\Models\TransactionRetryEvent;
use Illuminate\Http\Request;

test('retry events index resource formats model timestamps with timezone offsets', function (): void {
    $event = TransactionRetryEvent::instance();
    $event->setRawAttributes([
        'id'           => 1,
        'occurred_at'  => '2026-03-16 10:11:12',
        'created_at'   => '2026-03-16 10:11:12',
        'updated_at'   => '2026-03-16 10:11:12',
        'retry_status' => 'success',
    ], true);

    $resource = new RetryEventsIndexResource([$event], [
        'from' => '2026-03-16 00:00:00',
        'to'   => '2026-03-16 23:59:59',
    ]);

    $request = Request::create('/api/transaction-retry/events');
    $payload = [
        'data' => $resource->toArray($request),
        ...$resource->with($request),
    ];

    expect($payload['data'][0]['occurred_at'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['data'][0]['created_at'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['data'][0]['updated_at'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['meta']['from'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['meta']['to'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/');
});

test('exception group resource formats nested aggregate timestamps with timezone offsets', function (): void {
    $resource = new RetryExceptionGroupResource([
        'group' => (object) [
            'last_seen' => '2026-03-16 11:22:33',
        ],
        'occurrences' => [
            (object) [
                'occurred_at' => '2026-03-16 11:20:00',
            ],
        ],
        'series' => [
            [
                'timestamp' => '2026-03-16 11:00:00',
            ],
        ],
    ], [
        'from'      => '2026-03-16 00:00:00',
        'to'        => '2026-03-16 23:59:59',
        'last_seen' => '2026-03-16 11:22:33',
    ]);

    $request = Request::create('/api/transaction-retry/exceptions/group');
    $payload = [
        'data' => $resource->toArray($request),
        ...$resource->with($request),
    ];

    expect($payload['data']['group']['last_seen'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['data']['occurrences'][0]['occurred_at'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['data']['series'][0]['timestamp'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/')
        ->and($payload['meta']['last_seen'])->toMatch('/T\d{2}:\d{2}:\d{2}(?:\+|-)\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}Z/');
});

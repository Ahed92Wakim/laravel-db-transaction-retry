<?php

declare(strict_types=1);

use DatabaseTransactions\RetryHelper\Http\Controllers\TransactionRetryEventController;
use Illuminate\Support\Carbon;

it('uses transaction-style buckets for request and command charts', function (
    string $window,
    string $expectedBucket
): void {
    $controller    = new TransactionRetryEventController();
    $start         = Carbon::parse('2026-03-01 00:00:00');
    $end           = Carbon::parse('2026-03-18 00:00:00');
    $resolveBucket = Closure::bind(
        fn (string $range, Carbon $from, Carbon $to): string => $this->bucketForRequestChartWindow($range, $from, $to),
        $controller,
        TransactionRetryEventController::class
    );
    $bucket = $resolveBucket($window, $start, $end);

    expect($bucket)->toBe($expectedBucket);
})->with([
    ['1h', 'minute'],
    ['24h', '15minute'],
    ['7d', '2hour'],
    ['14d', '4hour'],
    ['30d', '8hour'],
]);

<?php

namespace DatabaseTransactions\RetryHelper\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionRetryEventController
{
    public function index(Request $request): JsonResponse
    {
        $table = (string) config('database-transaction-retry.logging.table', 'transaction_retry_events');
        $query = DB::table($table);

        $filters = [
            'retry_status' => $request->query('retry_status'),
            'log_level'    => $request->query('log_level'),
            'route_hash'   => $request->query('route_hash'),
            'query_hash'   => $request->query('query_hash'),
            'event_hash'   => $request->query('event_hash'),
            'method'       => $request->query('method'),
            'route_name'   => $request->query('route_name'),
            'user_id'      => $request->query('user_id'),
        ];

        foreach ($filters as $column => $value) {
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        $from = $request->query('from');
        if (is_string($from) && $from !== '') {
            $query->where('occurred_at', '>=', $from);
        }

        $to = $request->query('to');
        if (is_string($to) && $to !== '') {
            $query->where('occurred_at', '<=', $to);
        }

        $query->orderByDesc('occurred_at')->orderByDesc('id');

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $page    = max((int) $request->query('page', 1), 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'page'     => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total'    => $paginator->total(),
            ],
        ]);
    }

    public function show(int|string $id): JsonResponse
    {
        $table = (string) config('database-transaction-retry.logging.table', 'transaction_retry_events');
        $row   = DB::table($table)->where('id', $id)->first();

        if (! $row) {
            abort(404);
        }

        return response()->json(['data' => $row]);
    }

    public function today(Request $request): JsonResponse
    {
        $table         = (string) config('database-transaction-retry.logging.table', 'transaction_retry_events');
        [$start, $end] = $this->resolveRange($request, 'today');

        $total = DB::table($table)
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end)
            ->count();

        return response()->json([
            'data' => [
                'date'          => $start->toDateString(),
                'from'          => $start->toIso8601String(),
                'to'            => $end->toIso8601String(),
                'total_retries' => $total,
            ],
        ]);
    }

    public function traffic(Request $request): JsonResponse
    {
        $table                  = (string) config('database-transaction-retry.logging.table', 'transaction_retry_events');
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForWindow($window, $start, $end);

        $driver           = DB::getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver);
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $rows = DB::table($table)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw("SUM(CASE WHEN retry_status = 'attempt' THEN 1 ELSE 0 END) as attempts")
            ->selectRaw("SUM(CASE WHEN retry_status = 'success' THEN 1 ELSE 0 END) as recovered")
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $seriesByBucket = [];
        foreach ($rows as $row) {
            $seriesByBucket[(string) $row->bucket] = [
                'attempts'  => (int) $row->attempts,
                'recovered' => (int) $row->recovered,
            ];
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $metrics   = $seriesByBucket[$bucketKey] ?? ['attempts' => 0, 'recovered' => 0];

            $series[] = [
                'time'      => $cursor->format($labelFormat),
                'attempts'  => $metrics['attempts'],
                'recovered' => $metrics['recovered'],
            ];

            $cursor = match ($bucket) {
                'minute' => $cursor->addMinute(),
                'hour'   => $cursor->addHour(),
                default  => $cursor->addDay(),
            };
        }

        return response()->json([
            'data' => $series,
            'meta' => [
                'from'   => $start->toIso8601String(),
                'to'     => $end->toIso8601String(),
                'window' => $window,
                'bucket' => $bucket,
            ],
        ]);
    }

    private function resolveRange(Request $request, string $defaultWindow): array
    {
        $start  = $this->parseTimestamp($request->query('from'));
        $end    = $this->parseTimestamp($request->query('to'));
        $window = strtolower((string) $request->query('window', ''));

        if (! $start && ! $end && $window === '') {
            if ($defaultWindow === 'today') {
                $start = now()->startOfDay();
                $end   = now()->endOfDay();

                return [$start, $end, $defaultWindow];
            }

            $window = $defaultWindow;
        }

        if (! $start || ! $end) {
            $reference                 = $end ?? now();
            $windowKey                 = $window !== '' ? $window : $defaultWindow;
            [$windowStart, $windowEnd] = $this->windowToRange($windowKey, $reference);
            $start                     = $start ?? $windowStart;
            $end                       = $end   ?? $windowEnd;
            $window                    = $windowKey;
        }

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end, $window !== '' ? $window : $defaultWindow];
    }

    private function windowToRange(string $window, Carbon $reference): array
    {
        return match (strtolower($window)) {
            '1h'    => [$reference->copy()->subHour(), $reference->copy()],
            '24h'   => [$reference->copy()->subHours(24), $reference->copy()],
            '7d'    => [$reference->copy()->subDays(7), $reference->copy()],
            '14d'   => [$reference->copy()->subDays(14), $reference->copy()],
            '30d'   => [$reference->copy()->subDays(30), $reference->copy()],
            'today' => [$reference->copy()->startOfDay(), $reference->copy()->endOfDay()],
            default => [$reference->copy()->subHours(24), $reference->copy()],
        };
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function bucketForWindow(string $window, Carbon $start, Carbon $end): string
    {
        return match (strtolower($window)) {
            '1h'  => 'minute',
            '24h' => 'hour',
            '7d', '14d', '30d' => 'day',
            default => $this->bucketForDuration($start, $end),
        };
    }

    private function bucketForDuration(Carbon $start, Carbon $end): string
    {
        $hours = $start->diffInHours($end);

        if ($hours <= 2) {
            return 'minute';
        }

        if ($hours <= 48) {
            return 'hour';
        }

        return 'day';
    }

    private function bucketExpression(string $bucket, string $driver): string
    {
        $bucket = strtolower($bucket);
        $driver = strtolower($driver);

        if ($driver === 'pgsql') {
            return match ($bucket) {
                'minute' => "to_char(date_trunc('minute', occurred_at), 'YYYY-MM-DD HH24:MI:00')",
                'hour'   => "to_char(date_trunc('hour', occurred_at), 'YYYY-MM-DD HH24:00:00')",
                default  => "to_char(date_trunc('day', occurred_at), 'YYYY-MM-DD 00:00:00')",
            };
        }

        if ($driver === 'sqlite') {
            return match ($bucket) {
                'minute' => "strftime('%Y-%m-%d %H:%M:00', occurred_at)",
                'hour'   => "strftime('%Y-%m-%d %H:00:00', occurred_at)",
                default  => "strftime('%Y-%m-%d 00:00:00', occurred_at)",
            };
        }

        return match ($bucket) {
            'minute' => "DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:00')",
            'hour'   => "DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00')",
            default  => "DATE_FORMAT(occurred_at, '%Y-%m-%d 00:00:00')",
        };
    }

    private function bucketFormat(string $bucket): string
    {
        return match (strtolower($bucket)) {
            'minute' => 'Y-m-d H:i:00',
            'hour'   => 'Y-m-d H:00:00',
            default  => 'Y-m-d 00:00:00',
        };
    }

    private function labelFormat(string $bucket): string
    {
        return match (strtolower($bucket)) {
            'minute' => 'H:i',
            'hour'   => 'H:00',
            default  => 'M d',
        };
    }

    private function alignToBucket(Carbon $value, string $bucket): Carbon
    {
        return match (strtolower($bucket)) {
            'minute' => $value->copy()->second(0),
            'hour'   => $value->copy()->minute(0)->second(0),
            default  => $value->copy()->startOfDay(),
        };
    }
}

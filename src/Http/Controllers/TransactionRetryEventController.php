<?php

namespace DatabaseTransactions\RetryHelper\Http\Controllers;

use DatabaseTransactions\RetryHelper\Enums\RetryStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionRetryEventController
{
    public function index(Request $request): JsonResponse
    {
        $table = (string)config('database-transaction-retry.logging.table', 'transaction_retry_events');
        $query = DB::table($table);

        $filters = [
            'retry_status'   => $request->query('retry_status'),
            'log_level'      => $request->query('log_level'),
            'retry_group_id' => $request->query('retry_group_id'),
            'route_hash'     => $request->query('route_hash'),
            'query_hash'     => $request->query('query_hash'),
            'event_hash'     => $request->query('event_hash'),
            'method'         => $request->query('method'),
            'route_name'     => $request->query('route_name'),
            'user_id'        => $request->query('user_id'),
            'user_type'      => $request->query('user_type'),
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

        $perPage = min(max((int)$request->query('per_page', 50), 1), 200);
        $page    = max((int)$request->query('page', 1), 1);

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
        $table = (string)config('database-transaction-retry.logging.table', 'transaction_retry_events');
        $row   = DB::table($table)->where('id', $id)->first();

        if (!$row) {
            abort(404);
        }

        return response()->json(['data' => $row]);
    }

    public function today(Request $request): JsonResponse
    {
        $table         = (string)config('database-transaction-retry.logging.table', 'transaction_retry_events');
        [$start, $end] = $this->resolveRange($request, 'today');

        $baseQuery = DB::table($table)
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end);
        $attempt = (clone $baseQuery)
            ->where('retry_status', RetryStatus::Attempt->value)
            ->count();
        $success = (clone $baseQuery)
            ->where('retry_status', RetryStatus::Success->value)
            ->count();
        $failure = (clone $baseQuery)
            ->where('retry_status', RetryStatus::Failure->value)
            ->count();

        return response()->json([
            'data' => [
                'date'            => $start->toDateString(),
                'from'            => $start->toIso8601String(),
                'to'              => $end->toIso8601String(),
                'attempt_records' => $attempt,
                'success_records' => $success,
                'failure_records' => $failure,
            ],
        ]);
    }

    public function traffic(Request $request): JsonResponse
    {
        $table                  = (string)config('database-transaction-retry.logging.table', 'transaction_retry_events');
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForWindow($window, $start, $end);

        $driver           = DB::getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver);
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $attemptStatus = RetryStatus::Attempt->value;
        $successStatus = RetryStatus::Success->value;
        $failureStatus = RetryStatus::Failure->value;

        $rows = DB::table($table)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw(
                "SUM(CASE WHEN retry_status = '{$attemptStatus}' THEN 1 ELSE 0 END) as attempts"
            )
            ->selectRaw(
                "SUM(CASE WHEN retry_status = '{$successStatus}' THEN 1 ELSE 0 END) as success"
            )
            ->selectRaw(
                "SUM(CASE WHEN retry_status = '{$failureStatus}' THEN 1 ELSE 0 END) as failure"
            )
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $seriesByBucket = [];
        foreach ($rows as $row) {
            $seriesByBucket[(string)$row->bucket] = [
                'attempts' => (int)$row->attempts,
                'success'  => (int)$row->success,
                'failure'  => (int)$row->failure,
            ];
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $metrics   = $seriesByBucket[$bucketKey] ?? [
                'attempts' => 0,
                'success'  => 0,
                'failure'  => 0,
            ];

            $series[] = [
                'time'      => $cursor->format($labelFormat),
                'timestamp' => $cursor->toIso8601String(),
                'attempts'  => $metrics['attempts'],
                'success'   => $metrics['success'],
                'failure'   => $metrics['failure'],
                'recovered' => $metrics['success'],
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
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

    public function routes(Request $request): JsonResponse
    {
        $table                  = (string)config('database-transaction-retry.logging.table', 'transaction_retry_events');
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', 10);
        }
        $perPage = max((int)$perPageInput, 1);
        $page    = max((int)$request->query('page', 1), 1);

        $attemptStatus = RetryStatus::Attempt->value;
        $successStatus = RetryStatus::Success->value;
        $failureStatus = RetryStatus::Failure->value;

        $paginator = DB::table($table)
//            ->select(['route_hash', 'method', 'route_name', 'url'])
            ->selectRaw('ANY_VALUE(route_hash) as route_hash')
            ->selectRaw('ANY_VALUE(method) as method')
            ->selectRaw('ANY_VALUE(route_name) as route_name')
            ->selectRaw('ANY_VALUE(url) as url')
            ->selectRaw(
                "SUM(CASE WHEN retry_status = '{$attemptStatus}' THEN 1 ELSE 0 END) as attempts"
            )
            ->selectRaw(
                "SUM(CASE WHEN retry_status = '{$successStatus}' THEN 1 ELSE 0 END) as success"
            )
            ->selectRaw(
                "SUM(CASE WHEN retry_status = '{$failureStatus}' THEN 1 ELSE 0 END) as failure"
            )
            ->selectRaw('MAX(occurred_at) as last_seen')
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end)
            ->where(function ($query): void {
                $query->whereNotNull('route_name')->orWhereNotNull('url');
            })
//            ->groupBy('route_hash', 'method', 'route_name', 'url')
            ->groupBy('retry_group_id')
            ->orderByDesc('attempts')
            ->orderByDesc('failure')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'from'     => $start->toIso8601String(),
                'to'       => $end->toIso8601String(),
                'window'   => $window,
                'page'     => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total'    => $paginator->total(),
            ],
        ]);
    }

    public function routesVolume(Request $request): JsonResponse
    {
        $logTable               = (string)config('database-transaction-retry.slow_transactions.log_table', 'db_transaction_logs');
        $connection             = $this->resolveSlowTransactionConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', 10);
        }
        //        $perPage = min(max((int)$perPageInput, 1), 50);
        $perPage = $perPageInput;
        $page    = max((int)$request->query('page', 1), 1);

        $baseQuery = $connection->table($logTable)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<=', $end)
            ->where(function ($query): void {
                $query->whereNotNull('route_name')->orWhereNotNull('url');
            });

        $paginator = (clone $baseQuery)
            ->selectRaw('ANY_VALUE(http_method) as method')
            ->selectRaw('ANY_VALUE(route_name) as route_name')
            ->selectRaw('ANY_VALUE(url) as url')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(elapsed_ms) as avg_ms')
            ->selectRaw('SUM(CASE WHEN http_status BETWEEN 100 AND 399 THEN 1 ELSE 0 END) as status_1xx_3xx')
            ->selectRaw('SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as status_4xx')
            ->selectRaw('SUM(CASE WHEN http_status >= 500 THEN 1 ELSE 0 END) as status_5xx')
            ->groupBy('http_method', 'route_name', 'url')
            ->orderByDesc('total')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginator->getCollection()->map(function ($row) use ($baseQuery) {
            $count  = (int) ($row->total ?? 0);
            $offset = max((int) ceil($count * 0.95) - 1, 0);

            $p95 = (clone $baseQuery)
                ->when($row->method !== null, function ($query) use ($row): void {
                    $query->where('http_method', $row->method);
                }, function ($query): void {
                    $query->whereNull('http_method');
                })
                ->when($row->route_name !== null, function ($query) use ($row): void {
                    $query->where('route_name', $row->route_name);
                }, function ($query): void {
                    $query->whereNull('route_name');
                })
                ->when($row->url !== null, function ($query) use ($row): void {
                    $query->where('url', $row->url);
                }, function ($query): void {
                    $query->whereNull('url');
                })
                ->orderBy('elapsed_ms')
                ->offset($offset)
                ->limit(1)
                ->value('elapsed_ms');

            $row->avg_ms         = is_numeric($row->avg_ms) ? round((float) $row->avg_ms, 2) : 0;
            $row->p95_ms         = is_numeric($p95) ? (int) $p95 : 0;
            $row->status_1xx_3xx = (int) ($row->status_1xx_3xx ?? 0);
            $row->status_4xx     = (int) ($row->status_4xx ?? 0);
            $row->status_5xx     = (int) ($row->status_5xx ?? 0);
            $row->total          = (int) ($row->total ?? 0);

            return $row;
        });
        $paginator->setCollection($rows);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'from'     => $start->toIso8601String(),
                'to'       => $end->toIso8601String(),
                'window'   => $window,
                'page'     => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total'    => $paginator->total(),
            ],
        ]);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $table                  = (string)config('database-transaction-retry.exception_logging.table', 'db_exceptions');
        $connection             = $this->resolveExceptionLoggingConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $limit                  = min(max((int)$request->query('limit', 50), 1), 200);
        $driver                 = $connection->getDriverName();
        $bucket                 = $this->bucketForWindow($window, $start, $end);
        $bucketExpression       = $this->bucketExpression($bucket, $driver);
        $bucketFormat           = $this->bucketFormat($bucket);
        $labelFormat            = $this->labelFormat($bucket);
        $userKeyExpression      = $this->userKeyExpression($driver);

        if ($table === '') {
            return response()->json([
                'data' => [],
                'meta' => [
                    'from'              => $start->toIso8601String(),
                    'to'                => $end->toIso8601String(),
                    'window'            => $window,
                    'bucket'            => $bucket,
                    'limit'             => $limit,
                    'unique'            => 0,
                    'users'             => 0,
                    'total_occurrences' => 0,
                    'handled'           => 0,
                    'unhandled'         => 0,
                    'last_seen'         => null,
                    'series'            => [],
                ],
            ]);
        }

        $baseQuery = $connection->table($table)
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end);

        $uniqueCount = (clone $baseQuery)
            ->whereNotNull('event_hash')
            ->distinct()
            ->count('event_hash');

        $uniqueUsers = (clone $baseQuery)
            ->selectRaw("COUNT(DISTINCT {$userKeyExpression}) as users")
            ->value('users');

        $totals = (clone $baseQuery)
            ->selectRaw('COUNT(*) as occurrences')
            ->selectRaw('MAX(occurred_at) as last_seen')
            ->first();

        $rows = (clone $baseQuery)
            ->selectRaw('ANY_VALUE(exception_class) as exception_class')
            ->selectRaw('ANY_VALUE(error_message) as error_message')
            ->selectRaw('ANY_VALUE(sql_state) as sql_state')
            ->selectRaw('ANY_VALUE(driver_code) as driver_code')
            ->selectRaw('ANY_VALUE(connection) as connection')
            ->selectRaw('ANY_VALUE(method) as method')
            ->selectRaw('ANY_VALUE(route_name) as route_name')
            ->selectRaw('ANY_VALUE(url) as url')
            ->selectRaw('event_hash as event_hash')
            ->selectRaw('COUNT(*) as occurrences')
            ->selectRaw("COUNT(DISTINCT {$userKeyExpression}) as users")
            ->selectRaw('MAX(occurred_at) as last_seen')
            ->groupBy('event_hash')
            ->orderByDesc('occurrences')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get();

        $seriesRows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $metricsByBucket = [];
        foreach ($seriesRows as $row) {
            $metricsByBucket[(string)$row->bucket] = (int)$row->count;
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $series[]  = [
                'time'      => $cursor->format($labelFormat),
                'timestamp' => $cursor->toIso8601String(),
                'count'     => $metricsByBucket[$bucketKey] ?? 0,
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        $totalOccurrences = (int)($totals?->occurrences ?? 0);
        $lastSeen         = $totals?->last_seen ?? null;

        return response()->json([
            'data' => $rows,
            'meta' => [
                'from'              => $start->toIso8601String(),
                'to'                => $end->toIso8601String(),
                'window'            => $window,
                'bucket'            => $bucket,
                'limit'             => $limit,
                'unique'            => $uniqueCount,
                'users'             => is_numeric($uniqueUsers) ? (int)$uniqueUsers : 0,
                'total_occurrences' => $totalOccurrences,
                'handled'           => 0,
                'unhandled'         => $totalOccurrences,
                'last_seen'         => $lastSeen,
                'series'            => $series,
            ],
        ]);
    }

    public function exceptionGroup(Request $request, string $eventHash): JsonResponse
    {
        $table                  = (string)config('database-transaction-retry.exception_logging.table', 'db_exceptions');
        $connection             = $this->resolveExceptionLoggingConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPage                = min(max((int)$request->query('per_page', 20), 1), 200);
        $page                   = max((int)$request->query('page', 1), 1);

        $bucket           = $this->bucketForWindow($window, $start, $end);
        $driver           = $connection->getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver);
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        if ($table === '') {
            return response()->json([
                'data' => [
                    'group'       => null,
                    'occurrences' => [],
                    'series'      => [],
                ],
                'meta' => [
                    'from'     => $start->toIso8601String(),
                    'to'       => $end->toIso8601String(),
                    'window'   => $window,
                    'bucket'   => $bucket,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'total'    => 0,
                ],
            ]);
        }

        $baseQuery = $connection->table($table)
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $end)
            ->where('event_hash', $eventHash);

        $summary = (clone $baseQuery)
            ->selectRaw('ANY_VALUE(exception_class) as exception_class')
            ->selectRaw('ANY_VALUE(error_message) as error_message')
            ->selectRaw('ANY_VALUE(sql_state) as sql_state')
            ->selectRaw('ANY_VALUE(driver_code) as driver_code')
            ->selectRaw('ANY_VALUE(connection) as connection')
            ->selectRaw('ANY_VALUE(db_exceptions.sql) as `sql`')
            ->selectRaw('COUNT(*) as occurrences')
            ->selectRaw('MAX(occurred_at) as last_seen')
            ->first();

        $paginator = (clone $baseQuery)
            ->select([
                'id',
                'occurred_at',
                'sql',
                'raw_sql',
                'error_message',
                'method',
                'route_name',
                'url',
                'user_type',
                'user_id',
                'connection',
                'sql_state',
                'driver_code',
                'event_hash',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $seriesRows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $metricsByBucket = [];
        foreach ($seriesRows as $row) {
            $metricsByBucket[(string)$row->bucket] = (int)$row->count;
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $series[]  = [
                'time'      => $cursor->format($labelFormat),
                'timestamp' => $cursor->toIso8601String(),
                'count'     => $metricsByBucket[$bucketKey] ?? 0,
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        return response()->json([
            'data' => [
                'group'       => $summary,
                'occurrences' => $paginator->items(),
                'series'      => $series,
            ],
            'meta' => [
                'from'     => $start->toIso8601String(),
                'to'       => $end->toIso8601String(),
                'window'   => $window,
                'bucket'   => $bucket,
                'page'     => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total'    => $paginator->total(),
            ],
        ]);
    }

    public function queries(Request $request): JsonResponse
    {
        $logTable               = (string)config('database-transaction-retry.slow_transactions.log_table', 'db_transaction_logs');
        $queryTable             = (string)config('database-transaction-retry.slow_transactions.query_table', 'db_transaction_queries');
        $connection             = $this->resolveSlowTransactionConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForQueryWindow($window, $start, $end);

        $driver           = $connection->getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver, 'l.completed_at');
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $baseQuery = $connection->table("{$queryTable} as q")
            ->join("{$logTable} as l", 'q.transaction_log_id', '=', 'l.id')
            ->whereNotNull('l.completed_at')
            ->where('l.completed_at', '>=', $start)
            ->where('l.completed_at', '<=', $end);

        $rows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COUNT(DISTINCT l.id) as transaction_count')
            ->selectRaw('AVG(q.execution_time_ms) as avg_ms')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $metricsByBucket = [];
        foreach ($rows as $row) {
            $bucketKey        = (string)$row->bucket;
            $queryCount       = (int)$row->count;
            $transactionCount = (int)$row->transaction_count;
            $avgMs            = $queryCount > 0 ? round((float)$row->avg_ms, 2) : 0;

            $metricsByBucket[$bucketKey] = [
                'count'             => $queryCount,
                'transaction_count' => $transactionCount,
                'avg_ms'            => $avgMs,
                'p95_ms'            => 0,
            ];
        }

        $durationRows = $connection->table("{$logTable} as l")
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as transaction_volume')
            ->selectRaw(
                'SUM(CASE WHEN l.elapsed_ms < 2000 THEN 1 ELSE 0 END) as under_2s'
            )
            ->selectRaw(
                'SUM(CASE WHEN l.elapsed_ms >= 2000 THEN 1 ELSE 0 END) as over_2s'
            )
            ->whereNotNull('l.completed_at')
            ->where('l.completed_at', '>=', $start)
            ->where('l.completed_at', '<=', $end)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $durationMetricsByBucket = [];
        foreach ($durationRows as $row) {
            $bucketKey = (string)$row->bucket;

            $durationMetricsByBucket[$bucketKey] = [
                'transaction_volume' => (int)$row->transaction_volume,
                'under_2s'           => (int)$row->under_2s,
                'over_2s'            => (int)$row->over_2s,
            ];
        }

        foreach ($metricsByBucket as $bucketKey => $metrics) {
            if ($metrics['count'] === 0) {
                continue;
            }

            $offset = max((int)ceil($metrics['count'] * 0.95) - 1, 0);
            $p95Ms  = (clone $baseQuery)
                ->whereRaw("{$bucketExpression} = ?", [$bucketKey])
                ->orderBy('q.execution_time_ms')
                ->offset($offset)
                ->limit(1)
                ->value('q.execution_time_ms');

            $metricsByBucket[$bucketKey]['p95_ms'] = is_numeric($p95Ms) ? (int)$p95Ms : 0;
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $metrics   = $metricsByBucket[$bucketKey] ?? [
                'count'             => 0,
                'transaction_count' => 0,
                'avg_ms'            => 0,
                'p95_ms'            => 0,
            ];
            $duration = $durationMetricsByBucket[$bucketKey] ?? [
                'transaction_volume' => 0,
                'under_2s'           => 0,
                'over_2s'            => 0,
            ];

            $series[] = [
                'time'               => $cursor->format($labelFormat),
                'timestamp'          => $cursor->toIso8601String(),
                'count'              => $metrics['count'],
                'transaction_count'  => $metrics['transaction_count'],
                'avg_ms'             => $metrics['avg_ms'],
                'p95_ms'             => $metrics['p95_ms'],
                'transaction_volume' => $duration['transaction_volume'],
                'under_2s'           => $duration['under_2s'],
                'over_2s'            => $duration['over_2s'],
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
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
        $window = strtolower((string)$request->query('window', ''));

        if (!$start && !$end && $window === '') {
            if ($defaultWindow === 'today') {
                $start = now()->startOfDay();
                $end   = now()->endOfDay();

                return [$start, $end, $defaultWindow];
            }

            $window = $defaultWindow;
        }

        if (!$start || !$end) {
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
        if (!is_string($value) || $value === '') {
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

    private function bucketForQueryWindow(string $window, Carbon $start, Carbon $end): string
    {
        return match (strtolower($window)) {
            '24h'   => '15minute',
            '7d'    => '2hour',
            '14d'   => '4hour',
            '30d'   => '8hour',
            default => $this->bucketForWindow($window, $start, $end),
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

    private function bucketExpression(string $bucket, string $driver, string $column = 'occurred_at'): string
    {
        $bucket = strtolower($bucket);
        $driver = strtolower($driver);

        if ($driver === 'pgsql') {
            return match ($bucket) {
                'minute'   => "to_char(date_trunc('minute', {$column}), 'YYYY-MM-DD HH24:MI:00')",
                '15minute' => "to_char(date_trunc('hour', {$column}) + ((date_part('minute', {$column})::int / 15) * interval '15 minutes'), 'YYYY-MM-DD HH24:MI:00')",
                'hour'     => "to_char(date_trunc('hour', {$column}), 'YYYY-MM-DD HH24:00:00')",
                '2hour'    => "to_char(date_trunc('day', {$column}) + ((date_part('hour', {$column})::int / 2) * interval '2 hours'), 'YYYY-MM-DD HH24:00:00')",
                '4hour'    => "to_char(date_trunc('day', {$column}) + ((date_part('hour', {$column})::int / 4) * interval '4 hours'), 'YYYY-MM-DD HH24:00:00')",
                '8hour'    => "to_char(date_trunc('day', {$column}) + ((date_part('hour', {$column})::int / 8) * interval '8 hours'), 'YYYY-MM-DD HH24:00:00')",
                default    => "to_char(date_trunc('day', {$column}), 'YYYY-MM-DD 00:00:00')",
            };
        }

        if ($driver === 'sqlite') {
            return match ($bucket) {
                'minute'   => "strftime('%Y-%m-%d %H:%M:00', {$column})",
                '15minute' => "strftime('%Y-%m-%d %H:', {$column}) || printf('%02d', CAST((CAST(strftime('%M', {$column}) AS integer) / 15) AS integer) * 15) || ':00'",
                'hour'     => "strftime('%Y-%m-%d %H:00:00', {$column})",
                '2hour'    => "strftime('%Y-%m-%d ', {$column}) || printf('%02d', CAST((CAST(strftime('%H', {$column}) AS integer) / 2) AS integer) * 2) || ':00:00'",
                '4hour'    => "strftime('%Y-%m-%d ', {$column}) || printf('%02d', CAST((CAST(strftime('%H', {$column}) AS integer) / 4) AS integer) * 4) || ':00:00'",
                '8hour'    => "strftime('%Y-%m-%d ', {$column}) || printf('%02d', CAST((CAST(strftime('%H', {$column}) AS integer) / 8) AS integer) * 8) || ':00:00'",
                default    => "strftime('%Y-%m-%d 00:00:00', {$column})",
            };
        }

        return match ($bucket) {
            'minute'   => "DATE_FORMAT({$column}, '%Y-%m-%d %H:%i:00')",
            '15minute' => "DATE_FORMAT(DATE_ADD(DATE_FORMAT({$column}, '%Y-%m-%d %H:00:00'), INTERVAL FLOOR(MINUTE({$column}) / 15) * 15 MINUTE), '%Y-%m-%d %H:%i:00')",
            'hour'     => "DATE_FORMAT({$column}, '%Y-%m-%d %H:00:00')",
            '2hour'    => "DATE_FORMAT(DATE_ADD(DATE({$column}), INTERVAL FLOOR(HOUR({$column}) / 2) * 2 HOUR), '%Y-%m-%d %H:00:00')",
            '4hour'    => "DATE_FORMAT(DATE_ADD(DATE({$column}), INTERVAL FLOOR(HOUR({$column}) / 4) * 4 HOUR), '%Y-%m-%d %H:00:00')",
            '8hour'    => "DATE_FORMAT(DATE_ADD(DATE({$column}), INTERVAL FLOOR(HOUR({$column}) / 8) * 8 HOUR), '%Y-%m-%d %H:00:00')",
            default    => "DATE_FORMAT({$column}, '%Y-%m-%d 00:00:00')",
        };
    }

    private function userKeyExpression(string $driver): string
    {
        $driver = strtolower($driver);

        $concatExpression = match ($driver) {
            'pgsql', 'sqlite' => "COALESCE(user_type, '') || ':' || COALESCE(user_id, '')",
            default => "CONCAT_WS(':', COALESCE(user_type, ''), COALESCE(user_id, ''))",
        };

        return "CASE WHEN user_id IS NULL OR user_id = '' THEN NULL ELSE {$concatExpression} END";
    }

    private function bucketFormat(string $bucket): string
    {
        return match (strtolower($bucket)) {
            'minute', '15minute' => 'Y-m-d H:i:00',
            'hour', '2hour', '4hour', '8hour' => 'Y-m-d H:00:00',
            default => 'Y-m-d 00:00:00',
        };
    }

    private function labelFormat(string $bucket): string
    {
        return match (strtolower($bucket)) {
            'minute', '15minute' => 'H:i',
            'hour', '2hour', '4hour', '8hour' => 'H:00',
            default => 'M d',
        };
    }

    private function alignToBucket(Carbon $value, string $bucket): Carbon
    {
        return match (strtolower($bucket)) {
            'minute'   => $value->copy()->second(0),
            '15minute' => $this->alignToMinuteBucket($value, 15),
            'hour'     => $value->copy()->minute(0)->second(0),
            '2hour'    => $this->alignToHourBucket($value, 2),
            '4hour'    => $this->alignToHourBucket($value, 4),
            '8hour'    => $this->alignToHourBucket($value, 8),
            default    => $value->copy()->startOfDay(),
        };
    }

    private function alignToMinuteBucket(Carbon $value, int $bucketMinutes): Carbon
    {
        $aligned = $value->copy()->second(0);
        $minute  = intdiv($aligned->minute, $bucketMinutes) * $bucketMinutes;

        return $aligned->minute($minute);
    }

    private function alignToHourBucket(Carbon $value, int $bucketHours): Carbon
    {
        $aligned = $value->copy()->minute(0)->second(0);
        $hour    = intdiv($aligned->hour, $bucketHours) * $bucketHours;

        return $aligned->hour($hour);
    }

    private function advanceCursor(Carbon $cursor, string $bucket): Carbon
    {
        return match (strtolower($bucket)) {
            'minute'   => $cursor->addMinute(),
            '15minute' => $cursor->addMinutes(15),
            'hour'     => $cursor->addHour(),
            '2hour'    => $cursor->addHours(2),
            '4hour'    => $cursor->addHours(4),
            '8hour'    => $cursor->addHours(8),
            default    => $cursor->addDay(),
        };
    }

    private function resolveSlowTransactionConnection()
    {
        $connectionName = config('database-transaction-retry.slow_transactions.log_connection');

        return is_string($connectionName) && $connectionName !== ''
            ? DB::connection($connectionName)
            : DB::connection();
    }

    private function resolveExceptionLoggingConnection()
    {
        $connectionName = config('database-transaction-retry.exception_logging.connection');

        return is_string($connectionName) && $connectionName !== ''
            ? DB::connection($connectionName)
            : DB::connection();
    }

    private function percentile(array $values, float $percent): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $index = (int) ceil($percent * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return (int) $values[$index];
    }
}

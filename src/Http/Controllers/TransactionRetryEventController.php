<?php

namespace DatabaseTransactions\RetryHelper\Http\Controllers;

use DatabaseTransactions\RetryHelper\Enums\RetryStatus;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryEventShowResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryEventsIndexResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryEventsTodayResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryExceptionGroupResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryExceptionsResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryQueriesDurationResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryQueriesResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryQueriesVolumeResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryRequestDurationResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryRequestMetricsResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryRequestRoutesResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryRequestsResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryRoutesResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryTransactionLogsResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryTransactionQueriesResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryRoutesVolumeResource;
use DatabaseTransactions\RetryHelper\Http\Resources\RetryTrafficResource;
use DatabaseTransactions\RetryHelper\Models\DbException;
use DatabaseTransactions\RetryHelper\Models\QueryLog;
use DatabaseTransactions\RetryHelper\Models\RequestLog;
use DatabaseTransactions\RetryHelper\Models\SlowTransactionLog;
use DatabaseTransactions\RetryHelper\Models\TransactionRetryEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionRetryEventController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->retryEventModel()->newQuery();

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

        $perPage = min(max((int)$request->query('per_page', '50'), 1), 200);
        $page    = max((int)$request->query('page', '1'), 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return (new RetryEventsIndexResource($paginator->items(), [
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
        ]))->response($request);
    }

    public function show(int|string $id): JsonResponse
    {
        $row = $this->retryEventModel()
            ->newQuery()
            ->where('id', $id)
            ->first();

        if (!$row) {
            abort(404);
        }

        return (new RetryEventShowResource($row))->response();
    }

    public function today(Request $request): JsonResponse
    {
        [$start, $end] = $this->resolveRange($request, 'today');

        $baseQuery = $this->retryEventModel()
            ->newQuery()
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

        return (new RetryEventsTodayResource([
            'date'            => $start->toDateString(),
            'from'            => $start->toIso8601String(),
            'to'              => $end->toIso8601String(),
            'attempt_records' => $attempt,
            'success_records' => $success,
            'failure_records' => $failure,
        ]))->response($request);
    }

    public function traffic(Request $request): JsonResponse
    {
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForWindow($window, $start, $end);

        $driver           = DB::getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver);
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $attemptStatus = RetryStatus::Attempt->value;
        $successStatus = RetryStatus::Success->value;
        $failureStatus = RetryStatus::Failure->value;

        $rows = $this->retryEventModel()
            ->newQuery()
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

        return (new RetryTrafficResource($series, [
            'from'   => $start->toIso8601String(),
            'to'     => $end->toIso8601String(),
            'window' => $window,
            'bucket' => $bucket,
        ]))->response($request);
    }

    public function routes(Request $request): JsonResponse
    {
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', '10');
        }
        $perPage = max((int)$perPageInput, 1);
        $page    = max((int)$request->query('page', '1'), 1);

        $attemptStatus = RetryStatus::Attempt->value;
        $successStatus = RetryStatus::Success->value;
        $failureStatus = RetryStatus::Failure->value;

        $allowedRouteSort = [
            'method'   => 'method',
            'path'     => 'route_name',
            'attempts' => 'attempts',
            'success'  => 'success',
            'failure'  => 'failure',
        ];
        $routeSortBy  = (string)$request->query('sort_by', 'attempts');
        $routeSortDir = strtolower((string)$request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $routeSortCol = $allowedRouteSort[$routeSortBy] ?? 'attempts';

        $paginator = $this->retryEventModel()
            ->newQuery()
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
            ->groupBy('route_hash', 'method', 'route_name', 'url')
            ->orderBy($routeSortCol, $routeSortDir)
            ->paginate($perPage, ['*'], 'page', $page);

        return (new RetryRoutesResource($paginator->items(), [
            'from'     => $start->toIso8601String(),
            'to'       => $end->toIso8601String(),
            'window'   => $window,
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
        ]))->response($request);
    }

    public function routesVolume(Request $request): JsonResponse
    {
        $logModel               = $this->slowTransactionLogModel();
        $logTable               = $logModel->getTable();
        $connection             = $this->resolveSlowTransactionConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', '10');
        }
        //        $perPage = min(max((int)$perPageInput, 1), 50);
        $perPage = (int)$perPageInput;
        $page    = max((int)$request->query('page', '1'), 1);

        $allowedVolumeSort = [
            'method'         => 'method',
            'path'           => 'route_name',
            'total'          => 'total',
            'avg_ms'         => 'avg_ms',
            'status_1xx_3xx' => 'status_1xx_3xx',
            'status_4xx'     => 'status_4xx',
            'status_5xx'     => 'status_5xx',
        ];
        $volumeSortBy  = (string)$request->query('sort_by', 'total');
        $volumeSortDir = strtolower((string)$request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $volumeSortCol = $allowedVolumeSort[$volumeSortBy] ?? 'total';

        $baseQuery = $logModel
            ->newQuery()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<=', $end)
            ->where(function ($query): void {
                $query->whereNotNull('route_name')->orWhereNotNull('url');
            });

        $paginator = (clone $baseQuery)
            ->selectRaw('http_method as method')
            ->selectRaw('route_name as route_name')
            ->selectRaw('url as url')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(elapsed_ms) as avg_ms')
            ->selectRaw('SUM(CASE WHEN http_status BETWEEN 100 AND 399 THEN 1 ELSE 0 END) as status_1xx_3xx')
            ->selectRaw('SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as status_4xx')
            ->selectRaw('SUM(CASE WHEN http_status >= 500 THEN 1 ELSE 0 END) as status_5xx')
            ->groupBy('http_method', 'route_name', 'url')
            ->orderBy($volumeSortCol, $volumeSortDir)
            ->paginate($perPage, ['*'], 'page', $page);

        // Fetch all elapsed_ms values for each route combination in a single query
        $elapsedTimesByRoute = [];
        foreach ($paginator->items() as $row) {
            $routeKey = json_encode([
                'method'     => $row->method,
                'route_name' => $row->route_name,
                'url'        => $row->url,
            ]);
            $elapsedTimesByRoute[$routeKey] = [];
        }

        if (!empty($elapsedTimesByRoute)) {
            $elapsedTimes = (clone $baseQuery)
                ->selectRaw('http_method as method')
                ->selectRaw('route_name as route_name')
                ->selectRaw('url as url')
                ->selectRaw('elapsed_ms')
                ->orderBy('http_method')
                ->orderBy('route_name')
                ->orderBy('url')
                ->orderBy('elapsed_ms')
                ->get();

            foreach ($elapsedTimes as $record) {
                $routeKey = json_encode([
                    'method'     => $record->method,
                    'route_name' => $record->route_name,
                    'url'        => $record->url,
                ]);
                if (isset($elapsedTimesByRoute[$routeKey])) {
                    $elapsedTimesByRoute[$routeKey][] = (int)$record->elapsed_ms;
                }
            }
        }

        $rows = $paginator->getCollection()->map(function ($row) use ($elapsedTimesByRoute) {
            $routeKey = json_encode([
                'method'     => $row->method,
                'route_name' => $row->route_name,
                'url'        => $row->url,
            ]);

            $row->avg_ms = is_numeric($row->avg_ms) ? round((float) $row->avg_ms, 2) : 0;
            $row->p95_ms = isset($elapsedTimesByRoute[$routeKey])
                ? $this->calculateP95($elapsedTimesByRoute[$routeKey])
                : 0;
            $row->status_1xx_3xx = (int) ($row->status_1xx_3xx ?? 0);
            $row->status_4xx     = (int) ($row->status_4xx ?? 0);
            $row->status_5xx     = (int) ($row->status_5xx ?? 0);
            $row->total          = (int) ($row->total ?? 0);

            return $row;
        });
        $paginator->setCollection($rows);

        return (new RetryRoutesVolumeResource($paginator->items(), [
            'from'     => $start->toIso8601String(),
            'to'       => $end->toIso8601String(),
            'window'   => $window,
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
        ]))->response($request);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $exceptionModel         = $this->exceptionLogModel();
        $table                  = $exceptionModel->getTable();
        $connection             = $this->resolveExceptionLoggingConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', '10');
        }
        $perPage           = max((int)$perPageInput, 1);
        $page              = max((int)$request->query('page', '1'), 1);
        $driver            = $connection->getDriverName();
        $bucket            = $this->bucketForWindow($window, $start, $end);
        $bucketExpression  = $this->bucketExpression($bucket, $driver);
        $bucketFormat      = $this->bucketFormat($bucket);
        $labelFormat       = $this->labelFormat($bucket);
        $userKeyExpression = $this->userKeyExpression($driver);

        if ($table === '') {
            return (new RetryExceptionsResource([], [
                'from'              => $start->toIso8601String(),
                'to'                => $end->toIso8601String(),
                'window'            => $window,
                'bucket'            => $bucket,
                'page'              => $page,
                'per_page'          => $perPage,
                'total'             => 0,
                'limit'             => $perPage,
                'unique'            => 0,
                'users'             => 0,
                'total_occurrences' => 0,
                'handled'           => 0,
                'unhandled'         => 0,
                'last_seen'         => null,
                'series'            => [],
            ]))->response($request);
        }

        $baseQuery = $exceptionModel
            ->newQuery()
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

        $allowedExceptionSort = [
            'exception'   => 'occurrences', // exception_class is ANY_VALUE; use occurrences as proxy
            'error_code'  => 'occurrences', // sql_state is ANY_VALUE; use occurrences as proxy
            'occurrences' => 'occurrences',
            'users'       => 'users',
            'last_seen'   => 'last_seen',
        ];
        $exceptionSortBy  = (string)$request->query('sort_by', 'occurrences');
        $exceptionSortDir = strtolower((string)$request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $exceptionSortCol = $allowedExceptionSort[$exceptionSortBy] ?? 'occurrences';

        $paginator = (clone $baseQuery)
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
            ->orderBy($exceptionSortCol, $exceptionSortDir)
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginator->items();

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

        $totalOccurrences = (int)($totals->occurrences ?? 0);
        $lastSeen         = $totals->last_seen ?? null;

        return (new RetryExceptionsResource($rows, [
            'from'              => $start->toIso8601String(),
            'to'                => $end->toIso8601String(),
            'window'            => $window,
            'bucket'            => $bucket,
            'page'              => $paginator->currentPage(),
            'per_page'          => $paginator->perPage(),
            'total'             => $paginator->total(),
            'limit'             => $paginator->perPage(),
            'unique'            => $uniqueCount,
            'users'             => is_numeric($uniqueUsers) ? (int)$uniqueUsers : 0,
            'total_occurrences' => $totalOccurrences,
            'handled'           => 0,
            'unhandled'         => $totalOccurrences,
            'last_seen'         => $lastSeen,
            'series'            => $series,
        ]))->response($request);
    }

    public function exceptionGroup(Request $request, string $eventHash): JsonResponse
    {
        $exceptionModel         = $this->exceptionLogModel();
        $table                  = $exceptionModel->getTable();
        $connection             = $this->resolveExceptionLoggingConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', '10');
        }
        $perPage = max((int)$perPageInput, 1);
        $page    = max((int)$request->query('page', '1'), 1);

        $bucket           = $this->bucketForWindow($window, $start, $end);
        $driver           = $connection->getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver);
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        if ($table === '') {
            return (new RetryExceptionGroupResource([
                'group'       => null,
                'occurrences' => [],
                'series'      => [],
            ], [
                'from'     => $start->toIso8601String(),
                'to'       => $end->toIso8601String(),
                'window'   => $window,
                'bucket'   => $bucket,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => 0,
            ]))->response($request);
        }

        $baseQuery = $exceptionModel
            ->newQuery()
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
            ->selectRaw('ANY_VALUE(sql_query) as `sql`')
            ->selectRaw('COUNT(*) as occurrences')
            ->selectRaw('MAX(occurred_at) as last_seen')
            ->first();

        $paginator = (clone $baseQuery)
            ->select([
                'id',
                'occurred_at',
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
            ->selectRaw('sql_query as `sql`')
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

        return (new RetryExceptionGroupResource([
            'group'       => $summary,
            'occurrences' => $paginator->items(),
            'series'      => $series,
        ], [
            'from'     => $start->toIso8601String(),
            'to'       => $end->toIso8601String(),
            'window'   => $window,
            'bucket'   => $bucket,
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
        ]))->response($request);
    }

    public function queriesVolume(Request $request): JsonResponse
    {
        $logModel               = $this->slowTransactionLogModel();
        $logTable               = $logModel->getTable();
        $connection             = $this->resolveSlowTransactionConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForQueryWindow($window, $start, $end);

        $driver           = $connection->getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver, 'l.completed_at');
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $durationRows = $logModel
            ->newQuery()
            ->from("{$logTable} as l")
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as transaction_volume')
            ->selectRaw('COUNT(*) as transaction_count')
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
                'transaction_count'  => (int)$row->transaction_count,
                'under_2s'           => (int)$row->under_2s,
                'over_2s'            => (int)$row->over_2s,
            ];
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $duration  = $durationMetricsByBucket[$bucketKey] ?? [
                'transaction_volume' => 0,
                'transaction_count'  => 0,
                'under_2s'           => 0,
                'over_2s'            => 0,
            ];

            $series[] = [
                'time'               => $cursor->format($labelFormat),
                'timestamp'          => $cursor->toIso8601String(),
                'transaction_count'  => $duration['transaction_count'],
                'transaction_volume' => $duration['transaction_volume'],
                'under_2s'           => $duration['under_2s'],
                'over_2s'            => $duration['over_2s'],
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        return (new RetryQueriesVolumeResource($series, [
            'from'   => $start->toIso8601String(),
            'to'     => $end->toIso8601String(),
            'window' => $window,
            'bucket' => $bucket,
        ]))->response($request);
    }

    public function queriesDuration(Request $request): JsonResponse
    {
        $logModel               = $this->slowTransactionLogModel();
        $logTable               = $logModel->getTable();
        $queryModel             = $this->slowQueryLogModel();
        $queryTable             = $queryModel->getTable();
        $connection             = $this->resolveSlowTransactionConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForQueryWindow($window, $start, $end);

        $driver           = $connection->getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver, 'l.completed_at');
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $baseQuery = $queryModel
            ->newQuery()
            ->from("{$queryTable} as q")
            ->join("{$logTable} as l", 'q.loggable_id', '=', 'l.id')
            ->where('q.loggable_type', $logTable)
            ->whereNotNull('l.completed_at')
            ->where('l.completed_at', '>=', $start)
            ->where('l.completed_at', '<=', $end);

        $rows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('AVG(q.execution_time_ms) as avg_ms')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $metricsByBucket = [];
        foreach ($rows as $row) {
            $bucketKey  = (string)$row->bucket;
            $queryCount = (int)$row->count;
            $avgMs      = $queryCount > 0 ? round((float)$row->avg_ms, 2) : 0;

            $metricsByBucket[$bucketKey] = [
                'count'  => $queryCount,
                'avg_ms' => $avgMs,
                'p95_ms' => 0,
            ];
        }

        // Fetch all execution times grouped by bucket to calculate P95 in a single query
        $executionTimeRows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('q.execution_time_ms')
            ->orderBy('bucket')
            ->orderBy('q.execution_time_ms')
            ->get();

        $executionTimesByBucket = [];
        foreach ($executionTimeRows as $row) {
            $bucketKey = (string)$row->bucket;
            if (!isset($executionTimesByBucket[$bucketKey])) {
                $executionTimesByBucket[$bucketKey] = [];
            }
            $executionTimesByBucket[$bucketKey][] = (int)$row->execution_time_ms;
        }

        // Calculate P95 for each bucket
        foreach ($metricsByBucket as $bucketKey => $metrics) {
            $p95Ms = 0;
            if (isset($executionTimesByBucket[$bucketKey])) {
                $p95Ms = $this->calculateP95($executionTimesByBucket[$bucketKey]);
            }
            $metricsByBucket[$bucketKey]['p95_ms'] = $p95Ms;
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $metrics   = $metricsByBucket[$bucketKey] ?? [
                'count'  => 0,
                'avg_ms' => 0,
                'p95_ms' => 0,
            ];

            $series[] = [
                'time'      => $cursor->format($labelFormat),
                'timestamp' => $cursor->toIso8601String(),
                'count'     => $metrics['count'],
                'avg_ms'    => $metrics['avg_ms'],
                'p95_ms'    => $metrics['p95_ms'],
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        return (new RetryQueriesDurationResource($series, [
            'from'   => $start->toIso8601String(),
            'to'     => $end->toIso8601String(),
            'window' => $window,
            'bucket' => $bucket,
        ]))->response($request);
    }

    public function queries(Request $request): JsonResponse
    {
        $logModel               = $this->slowTransactionLogModel();
        $logTable               = $logModel->getTable();
        $queryModel             = $this->slowQueryLogModel();
        $queryTable             = $queryModel->getTable();
        $connection             = $this->resolveSlowTransactionConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $bucket                 = $this->bucketForQueryWindow($window, $start, $end);

        $driver           = $connection->getDriverName();
        $bucketExpression = $this->bucketExpression($bucket, $driver, 'l.completed_at');
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $baseQuery = $queryModel
            ->newQuery()
            ->from("{$queryTable} as q")
            ->join("{$logTable} as l", 'q.loggable_id', '=', 'l.id')
            ->where('q.loggable_type', $logTable)
            ->whereNotNull('l.completed_at')
            ->where('l.completed_at', '>=', $start)
            ->where('l.completed_at', '<=', $end);

        $filterMethod    = $request->query('method');
        $filterRouteName = $request->query('route_name');
        $filterUrl       = $request->query('url');

        if (is_string($filterMethod) && $filterMethod !== '') {
            $baseQuery->where('l.http_method', $filterMethod);
        }
        if (is_string($filterRouteName) && $filterRouteName !== '') {
            $baseQuery->where('l.route_name', $filterRouteName);
        } elseif (is_string($filterUrl) && $filterUrl !== '') {
            $baseQuery->where('l.url', $filterUrl);
        }

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

        $durationQuery = $logModel
            ->newQuery()
            ->from("{$logTable} as l")
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
            ->where('l.completed_at', '<=', $end);

        if (is_string($filterMethod) && $filterMethod !== '') {
            $durationQuery->where('l.http_method', $filterMethod);
        }
        if (is_string($filterRouteName) && $filterRouteName !== '') {
            $durationQuery->where('l.route_name', $filterRouteName);
        } elseif (is_string($filterUrl) && $filterUrl !== '') {
            $durationQuery->where('l.url', $filterUrl);
        }

        $durationRows = $durationQuery->groupBy('bucket')->orderBy('bucket')->get();

        $durationMetricsByBucket = [];
        foreach ($durationRows as $row) {
            $bucketKey = (string)$row->bucket;

            $durationMetricsByBucket[$bucketKey] = [
                'transaction_volume' => (int)$row->transaction_volume,
                'under_2s'           => (int)$row->under_2s,
                'over_2s'            => (int)$row->over_2s,
            ];
        }

        // Fetch all execution times grouped by bucket to calculate P95 in a single query
        $executionTimeRows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('q.execution_time_ms')
            ->orderBy('bucket')
            ->orderBy('q.execution_time_ms')
            ->get();

        $executionTimesByBucket = [];
        foreach ($executionTimeRows as $row) {
            $bucketKey = (string)$row->bucket;
            if (!isset($executionTimesByBucket[$bucketKey])) {
                $executionTimesByBucket[$bucketKey] = [];
            }
            $executionTimesByBucket[$bucketKey][] = (int)$row->execution_time_ms;
        }

        // Calculate P95 for each bucket
        foreach ($metricsByBucket as $bucketKey => $metrics) {
            $p95Ms = 0;
            if (isset($executionTimesByBucket[$bucketKey])) {
                $p95Ms = $this->calculateP95($executionTimesByBucket[$bucketKey]);
            }
            $metricsByBucket[$bucketKey]['p95_ms'] = $p95Ms;
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

        return (new RetryQueriesResource($series, [
            'from'   => $start->toIso8601String(),
            'to'     => $end->toIso8601String(),
            'window' => $window,
            'bucket' => $bucket,
        ]))->response($request);
    }

    public function requests(Request $request): JsonResponse
    {
        $query = $this->requestLogModel()->newQuery();

        $type = strtolower((string)$request->query('type', 'http'));
        $this->applyRequestTypeFilter($query, $type);
        $this->applyRequestRouteFilters($query, $request);

        $status = $request->query('status');
        if (is_numeric($status)) {
            $query->where('http_status', (int)$status);
        }

        $search = trim((string)$request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('route_name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('http_method', 'like', "%{$search}%");
            });
        }

        $from = $this->parseTimestamp($request->query('from'));
        if ($from) {
            $query->where('completed_at', '>=', $from);
        }

        $to = $this->parseTimestamp($request->query('to'));
        if ($to) {
            $query->where('completed_at', '<=', $to);
        }

        $query->orderByDesc('completed_at')->orderByDesc('id');

        $perPage = min(max((int)$request->query('per_page', '50'), 1), 200);
        $page    = max((int)$request->query('page', '1'), 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return (new RetryRequestsResource($paginator->items(), [
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
        ]))->response($request);
    }

    public function transactionLogs(Request $request): JsonResponse
    {
        $query = $this->slowTransactionLogModel()->newQuery();

        $from = $this->parseTimestamp($request->query('from'));
        if ($from) {
            $query->where('completed_at', '>=', $from);
        }

        $to = $this->parseTimestamp($request->query('to'));
        if ($to) {
            $query->where('completed_at', '<=', $to);
        }

        $method = $request->query('method');
        if (is_string($method) && $method !== '') {
            $query->where('http_method', $method);
        }

        $routeName = $request->query('route_name');
        if (is_string($routeName) && $routeName !== '') {
            $query->where('route_name', $routeName);
        } else {
            $url = $request->query('url');
            if (is_string($url) && $url !== '') {
                $query->where('url', $url);
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('route_name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('http_method', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('completed_at')->orderByDesc('id');

        $perPage = min(max((int) $request->query('per_page', '20'), 1), 200);
        $page    = max((int) $request->query('page', '1'), 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return (new RetryTransactionLogsResource($paginator->items(), [
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
        ]))->response($request);
    }

    public function transactionQueries(int|string $id): JsonResponse
    {
        $logModel   = $this->slowTransactionLogModel();
        $logTable   = $logModel->getTable();
        $queryModel = $this->slowQueryLogModel();

        $log = $logModel->newQuery()->where('id', $id)->first();

        if (! $log) {
            abort(404);
        }

        $queries = $queryModel
            ->newQuery()
            ->where('loggable_id', $id)
            ->where('loggable_type', $logTable)
            ->orderBy('query_order')
            ->get();

        $logData = [
            'id'                 => $log->id,
            'completed_at'       => $log->completed_at,
            'http_method'        => $log->http_method,
            'route_name'         => $log->route_name,
            'url'                => $log->url,
            'http_status'        => $log->http_status,
            'elapsed_ms'         => $log->elapsed_ms,
            'total_queries_count'=> $log->total_queries_count,
            'slow_queries_count' => $log->slow_queries_count,
        ];

        return (new RetryTransactionQueriesResource($queries->all(), [
            'transaction' => $logData,
        ]))->response();
    }

    public function requestMetrics(Request $request): JsonResponse
    {
        $connection             = $this->resolveRequestLoggingConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $type                   = strtolower((string)$request->query('type', 'http'));

        $driver           = $connection->getDriverName();
        $bucket           = $this->bucketForRequestChartWindow($window, $start, $end);
        $bucketExpression = $this->bucketExpression($bucket, $driver, 'completed_at');
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $baseQuery = $this->requestLogModel()
            ->newQuery()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<=', $end);
        $this->applyRequestTypeFilter($baseQuery, $type);
        $this->applyRequestRouteFilters($baseQuery, $request);

        $rows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN http_status BETWEEN 100 AND 399 OR (http_method = 'CLI' AND http_status IS NULL) THEN 1 ELSE 0 END) as status_1xx_3xx")
            ->selectRaw('SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as status_4xx')
            ->selectRaw('SUM(CASE WHEN http_status >= 500 THEN 1 ELSE 0 END) as status_5xx')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $metricsByBucket = [];
        foreach ($rows as $row) {
            $bucketKey                   = (string)$row->bucket;
            $metricsByBucket[$bucketKey] = [
                'total'          => (int)($row->total ?? 0),
                'status_1xx_3xx' => (int)($row->status_1xx_3xx ?? 0),
                'status_4xx'     => (int)($row->status_4xx ?? 0),
                'status_5xx'     => (int)($row->status_5xx ?? 0),
            ];
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $metrics   = $metricsByBucket[$bucketKey] ?? [
                'total'          => 0,
                'status_1xx_3xx' => 0,
                'status_4xx'     => 0,
                'status_5xx'     => 0,
            ];

            $series[] = [
                'time'           => $cursor->format($labelFormat),
                'timestamp'      => $cursor->toIso8601String(),
                'total'          => $metrics['total'],
                'status_1xx_3xx' => $metrics['status_1xx_3xx'],
                'status_4xx'     => $metrics['status_4xx'],
                'status_5xx'     => $metrics['status_5xx'],
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        return (new RetryRequestMetricsResource($series, [
            'from'   => $start->toIso8601String(),
            'to'     => $end->toIso8601String(),
            'window' => $window,
            'bucket' => $bucket,
            'type'   => $type,
        ]))->response($request);
    }

    public function requestDuration(Request $request): JsonResponse
    {
        $connection             = $this->resolveRequestLoggingConnection();
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $type                   = strtolower((string)$request->query('type', 'http'));

        $driver           = $connection->getDriverName();
        $bucket           = $this->bucketForRequestChartWindow($window, $start, $end);
        $bucketExpression = $this->bucketExpression($bucket, $driver, 'completed_at');
        $bucketFormat     = $this->bucketFormat($bucket);
        $labelFormat      = $this->labelFormat($bucket);

        $baseQuery = $this->requestLogModel()
            ->newQuery()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<=', $end);
        $this->applyRequestTypeFilter($baseQuery, $type);
        $this->applyRequestRouteFilters($baseQuery, $request);

        $rows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('AVG(elapsed_ms) as avg_ms')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $metricsByBucket = [];
        foreach ($rows as $row) {
            $bucketKey                   = (string)$row->bucket;
            $count                       = (int)($row->count ?? 0);
            $avgMs                       = $count > 0 ? round((float)$row->avg_ms, 2) : 0;
            $metricsByBucket[$bucketKey] = [
                'count'  => $count,
                'avg_ms' => $avgMs,
                'p95_ms' => 0,
            ];
        }

        // Fetch all elapsed_ms values grouped by bucket to calculate P95 in a single query
        $elapsedTimeRows = (clone $baseQuery)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw('elapsed_ms')
            ->orderBy('bucket')
            ->orderBy('elapsed_ms')
            ->get();

        $elapsedTimesByBucket = [];
        foreach ($elapsedTimeRows as $row) {
            $bucketKey = (string)$row->bucket;
            if (!isset($elapsedTimesByBucket[$bucketKey])) {
                $elapsedTimesByBucket[$bucketKey] = [];
            }
            $elapsedTimesByBucket[$bucketKey][] = (int)$row->elapsed_ms;
        }

        // Calculate P95 for each bucket
        foreach ($metricsByBucket as $bucketKey => $metrics) {
            $p95Ms = 0;
            if (isset($elapsedTimesByBucket[$bucketKey])) {
                $p95Ms = $this->calculateP95($elapsedTimesByBucket[$bucketKey]);
            }
            $metricsByBucket[$bucketKey]['p95_ms'] = $p95Ms;
        }

        $seriesStart = $this->alignToBucket($start, $bucket);
        $seriesEnd   = $this->alignToBucket($end, $bucket);

        $series = [];
        $cursor = $seriesStart->copy();
        while ($cursor->lte($seriesEnd)) {
            $bucketKey = $cursor->format($bucketFormat);
            $metrics   = $metricsByBucket[$bucketKey] ?? [
                'count'  => 0,
                'avg_ms' => 0,
                'p95_ms' => 0,
            ];

            $series[] = [
                'time'      => $cursor->format($labelFormat),
                'timestamp' => $cursor->toIso8601String(),
                'count'     => $metrics['count'],
                'avg_ms'    => $metrics['avg_ms'],
                'p95_ms'    => $metrics['p95_ms'],
            ];

            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        $totalCount = (int)(clone $baseQuery)->count();
        $avgMs      = $totalCount > 0 ? round((float)(clone $baseQuery)->avg('elapsed_ms'), 2) : 0;
        $minMs      = $totalCount > 0 ? (int)(clone $baseQuery)->min('elapsed_ms') : 0;
        $maxMs      = $totalCount > 0 ? (int)(clone $baseQuery)->max('elapsed_ms') : 0;
        $p95Ms      = 0;
        if ($totalCount > 0) {
            $allElapsedTimes = (clone $baseQuery)
                ->pluck('elapsed_ms')
                ->map(static fn ($v) => (int)$v)
                ->all();
            $p95Ms = $this->calculateP95($allElapsedTimes);
        }

        return (new RetryRequestDurationResource($series, [
            'from'   => $start->toIso8601String(),
            'to'     => $end->toIso8601String(),
            'window' => $window,
            'bucket' => $bucket,
            'type'   => $type,
            'count'  => $totalCount,
            'avg_ms' => $avgMs,
            'min_ms' => $minMs,
            'max_ms' => $maxMs,
            'p95_ms' => $p95Ms,
        ]))->response($request);
    }

    public function requestRoutes(Request $request): JsonResponse
    {
        [$start, $end, $window] = $this->resolveRange($request, '24h');
        $type                   = strtolower((string)$request->query('type', 'http'));
        $perPageInput           = $request->query('per_page');
        if ($perPageInput === null) {
            $perPageInput = $request->query('limit', '10');
        }
        $perPage = max((int)$perPageInput, 1);
        $page    = max((int)$request->query('page', '1'), 1);

        $baseQuery = $this->requestLogModel()
            ->newQuery()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<=', $end)
            ->where(function ($query): void {
                $query->whereNotNull('route_name')->orWhereNotNull('url');
            });
        $this->applyRequestTypeFilter($baseQuery, $type);

        $allowedRequestRouteSort = [
            'method'         => 'http_method',
            'path'           => 'route_name',
            'total'          => 'total',
            'avg_ms'         => 'avg_ms',
            'status_1xx_3xx' => 'status_1xx_3xx',
            'status_4xx'     => 'status_4xx',
            'status_5xx'     => 'status_5xx',
        ];
        $requestRouteSortBy  = (string)$request->query('sort_by', 'total');
        $requestRouteSortDir = strtolower((string)$request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $requestRouteSortCol = $allowedRequestRouteSort[$requestRouteSortBy] ?? 'total';

        $paginator = (clone $baseQuery)
            ->selectRaw('ANY_VALUE(http_method) as method')
            ->selectRaw('ANY_VALUE(route_name) as route_name')
            ->selectRaw('ANY_VALUE(url) as url')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(elapsed_ms) as avg_ms')
            ->selectRaw("SUM(CASE WHEN http_status BETWEEN 100 AND 399 OR (http_method = 'CLI' AND http_status IS NULL) THEN 1 ELSE 0 END) as status_1xx_3xx")
            ->selectRaw('SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as status_4xx')
            ->selectRaw('SUM(CASE WHEN http_status >= 500 THEN 1 ELSE 0 END) as status_5xx')
            ->groupBy('http_method', 'route_name', 'url')
            ->orderBy($requestRouteSortCol, $requestRouteSortDir)
            ->paginate($perPage, ['*'], 'page', $page);

        // Pre-compute P95 values for each row in the paginator by fetching all elapsed_ms in one query
        $elapsedTimesByRoute = [];

        $elapsedTimes = (clone $baseQuery)
            ->select('http_method', 'route_name', 'url', 'elapsed_ms')
            ->orderBy('http_method')
            ->orderBy('route_name')
            ->orderBy('url')
            ->orderBy('elapsed_ms')
            ->get();

        foreach ($elapsedTimes as $record) {
            $routeKey = json_encode([
                'method'     => $record->http_method,
                'route_name' => $record->route_name,
                'url'        => $record->url,
            ]);
            if (!isset($elapsedTimesByRoute[$routeKey])) {
                $elapsedTimesByRoute[$routeKey] = [];
            }
            $elapsedTimesByRoute[$routeKey][] = (int)$record->elapsed_ms;
        }

        $rows = $paginator->getCollection()->map(function ($row) use ($elapsedTimesByRoute) {
            $routeKey = json_encode([
                'method'     => $row->method,
                'route_name' => $row->route_name,
                'url'        => $row->url,
            ]);

            $row->avg_ms = is_numeric($row->avg_ms) ? round((float)$row->avg_ms, 2) : 0;
            $row->p95_ms = isset($elapsedTimesByRoute[$routeKey])
                ? $this->calculateP95($elapsedTimesByRoute[$routeKey])
                : 0;
            $row->status_1xx_3xx = (int)($row->status_1xx_3xx ?? 0);
            $row->status_4xx     = (int)($row->status_4xx ?? 0);
            $row->status_5xx     = (int)($row->status_5xx ?? 0);
            $row->total          = (int)($row->total ?? 0);

            return $row;
        });
        $paginator->setCollection($rows);

        return (new RetryRequestRoutesResource($paginator->items(), [
            'from'     => $start->toIso8601String(),
            'to'       => $end->toIso8601String(),
            'window'   => $window,
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
            'type'     => $type,
        ]))->response($request);
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

    private function bucketForRequestChartWindow(string $window, Carbon $start, Carbon $end): string
    {
        return $this->bucketForQueryWindow($window, $start, $end);
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

    private function retryEventModel(): TransactionRetryEvent
    {
        return TransactionRetryEvent::instance(
            $this->resolveTableName('database-transaction-retry.logging.table', 'transaction_retry_events')
        );
    }

    private function slowTransactionLogModel(): SlowTransactionLog
    {
        return SlowTransactionLog::instance(
            $this->resolveTableName('database-transaction-retry.slow_transactions.log_table', 'db_transaction_logs'),
            $this->resolveConnectionName('database-transaction-retry.slow_transactions.log_connection')
        );
    }

    private function slowQueryLogModel(): QueryLog
    {
        return QueryLog::instance(
            $this->resolveTableName('database-transaction-retry.slow_transactions.query_table', 'db_query_logs'),
            $this->resolveConnectionName('database-transaction-retry.slow_transactions.log_connection')
        );
    }

    private function exceptionLogModel(): DbException
    {
        return DbException::instance(
            $this->resolveTableName('database-transaction-retry.exception_logging.table', 'db_exceptions'),
            $this->resolveConnectionName('database-transaction-retry.exception_logging.connection')
        );
    }

    private function requestLogModel(): RequestLog
    {
        return RequestLog::instance(
            $this->resolveTableName('database-transaction-retry.request_logging.log_table', 'db_request_logs'),
            $this->resolveConnectionName('database-transaction-retry.request_logging.log_connection')
        );
    }

    private function resolveTableName(string $key, string $default): string
    {
        $table = trim((string) config($key, $default));

        return $table !== '' ? $table : $default;
    }

    private function resolveConnectionName(string $key): ?string
    {
        $connectionName = trim((string) config($key, ''));

        return $connectionName !== '' ? $connectionName : null;
    }

    private function resolveSlowTransactionConnection()
    {
        $connectionName = $this->resolveConnectionName('database-transaction-retry.slow_transactions.log_connection');

        return $connectionName !== null
            ? DB::connection($connectionName)
            : DB::connection();
    }

    private function resolveExceptionLoggingConnection()
    {
        $connectionName = $this->resolveConnectionName('database-transaction-retry.exception_logging.connection');

        return $connectionName !== null
            ? DB::connection($connectionName)
            : DB::connection();
    }

    private function resolveRequestLoggingConnection()
    {
        $connectionName = $this->resolveConnectionName('database-transaction-retry.request_logging.log_connection');

        return $connectionName !== null
            ? DB::connection($connectionName)
            : DB::connection();
    }

    /**
     * Calculate P95 percentile from an array of values
     */
    private function calculateP95(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $index = (int)ceil($count * 0.95) - 1;
        $index = max($index, 0);

        return (int)($values[$index] ?? 0);
    }

    private function applyRequestTypeFilter($query, string $type): void
    {
        if ($type === 'command') {
            $query->where('http_method', 'CLI');

            return;
        }

        if ($type === 'http') {
            $query->where(function ($builder): void {
                $builder->whereNull('http_method')->orWhere('http_method', '!=', 'CLI');
            });
        }
    }

    private function applyRequestRouteFilters($query, Request $request): void
    {
        $method = $request->query('method');
        if (is_string($method) && $method !== '') {
            $query->where('http_method', $method);
        }

        $routeName = $request->query('route_name');
        if (is_string($routeName) && $routeName !== '') {
            $query->where('route_name', $routeName);

            return;
        }

        $url = $request->query('url');
        if (is_string($url) && $url !== '') {
            $query->where('url', $url);
        }
    }
}

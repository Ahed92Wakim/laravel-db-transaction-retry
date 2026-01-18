<?php

namespace DatabaseTransactions\RetryHelper\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}

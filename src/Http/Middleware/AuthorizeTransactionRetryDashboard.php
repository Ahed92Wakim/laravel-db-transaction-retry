<?php

namespace DatabaseTransactions\RetryHelper\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeTransactionRetryDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        $gate = (string) config('database-transaction-retry.dashboard.gate', 'viewTransactionRetryDashboard');
        if ($gate !== '' && Gate::denies($gate)) {
            abort(403);
        }

        return $next($request);
    }
}

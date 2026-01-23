<?php

namespace DatabaseTransactions\RetryHelper\Http\Middleware;

use Closure;
use DatabaseTransactions\RetryHelper\TransactionRetryDashboard;
use Illuminate\Http\Request;

class AuthorizeTransactionRetryDashboard
{
    public function handle(Request $request, Closure $next)
    {
        return TransactionRetryDashboard::check($request) ? $next($request) : abort(403);
    }
}

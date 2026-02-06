<?php

namespace DatabaseTransactions\RetryHelper\Http\Middleware;

use Closure;
use DatabaseTransactions\RetryHelper\TransactionRetryDashboard;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

class AuthorizeTransactionRetryDashboard
{
    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        if (TransactionRetryDashboard::check($request)) {
            return $next($request);
        }

        if (! $request->user()) {
            throw new AuthenticationException();
        }

        abort(403);
    }
}

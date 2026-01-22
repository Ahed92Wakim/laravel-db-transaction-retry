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
        if (app()->environment('local')) {
            return $next($request);
        }

        $gate = 'viewTransactionRetryDashboard';
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (Gate::forUser($user)->denies($gate)) {
            abort(403);
        }

        return $next($request);
    }
}

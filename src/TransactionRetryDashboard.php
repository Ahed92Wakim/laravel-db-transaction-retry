<?php

namespace DatabaseTransactions\RetryHelper;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransactionRetryDashboard
{
    /**
     * @var callable|null
     */
    protected static $authUsing;

    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        $callback = static::$authUsing ?? static function (Request $request): bool {
            return app()->environment('local');
        };

        return (bool) $callback($request);
    }

    public static function gate(Request $request): bool
    {
        return Gate::check('viewTransactionRetryDashboard', [$request->user()]);
    }
}

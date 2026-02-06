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
        return (static::$authUsing ?: function (Request $request) {
            return app()->environment('local') || static::gate($request);
        })($request);
    }

    public static function gate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return Gate::forUser($user)->check('viewTransactionRetryDashboard');
    }

    /**
     * Reset the auth callback (mainly for testing).
     */
    public static function resetAuth(): void
    {
        static::$authUsing = null;
    }
}

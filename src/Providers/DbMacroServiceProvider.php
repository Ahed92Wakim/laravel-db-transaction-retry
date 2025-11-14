<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use Closure;
use DatabaseTransactions\RetryHelper\Services\TransactionRetrier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class DbMacroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerDbFacadeMacro();
    }

    protected function registerDbFacadeMacro(): void
    {
        $macro = function (
            Closure $callback,
            ?int $maxRetries = null,
            ?int $retryDelay = null,
            ?string $logFileName = null,
            string $trxLabel = ''
        ) {
            return TransactionRetrier::runWithRetry(
                $callback,
                $maxRetries,
                $retryDelay,
                $logFileName,
                $trxLabel
            );
        };

        if (is_callable([DB::class, 'macro']) && ! DB::hasMacro('transactionWithRetry')) {
            DB::macro('transactionWithRetry', $macro);
        }

        if (
            method_exists(Connection::class, 'macro')
            && method_exists(Connection::class, 'hasMacro')
            && ! Connection::hasMacro('transactionWithRetry')
        ) {
            Connection::macro('transactionWithRetry', $macro);
        }
    }
}

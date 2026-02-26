<?php

namespace DatabaseTransactions\RetryHelper\Database;

use Closure;
use DatabaseTransactions\RetryHelper\Services\TransactionRetrier;

trait RetryableConnection
{
    /**
     * Whether the current call is already inside the retry wrapper.
     *
     * Prevents infinite recursion because TransactionRetrier::runWithRetry()
     * internally calls DB::transaction() which would re-enter this override.
     */
    private static bool $insideRetry = false;

    /**
     * Execute a Closure within a transaction, optionally with deadlock retry logic.
     *
     * When the global hook is enabled this method routes through
     * TransactionRetrier::runWithRetry() so that every DB::transaction()
     * call benefits from retry/backoff automatically.
     *
     * @template TReturn of mixed
     *
     * @param  (\Closure(static): TReturn)  $callback
     * @param  int  $attempts
     * @return TReturn
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        if (static::$insideRetry) {
            return parent::transaction($callback, $attempts);
        }

        if (! $this->isGlobalHookEnabled()) {
            return parent::transaction($callback, $attempts);
        }

        try {
            static::$insideRetry = true;

            return TransactionRetrier::runWithRetry($callback);
        } finally {
            static::$insideRetry = false;
        }
    }

    /**
     * Check whether the global transaction hook is enabled at runtime.
     */
    private function isGlobalHookEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        $config = config('database-transaction-retry.global_hook', []);

        if (! is_array($config)) {
            return false;
        }

        return ! empty($config['enabled']);
    }
}

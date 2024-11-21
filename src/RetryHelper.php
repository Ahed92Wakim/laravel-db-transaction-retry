<?php

namespace MysqlDeadlocks\RetryHelper;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class RetryHelper
{
    /**
     * Perform a database transaction with retry logic in case of deadlocks.
     *
     * @param callable $callback The transaction logic to execute.
     * @param int $maxRetries Number of times to retry on deadlock.
     * @param int $retryDelay Delay between retries in milliseconds.
     * @return mixed
     * @throws QueryException
     */
    public static function transactionWithRetry(callable $callback, $maxRetries = 5, $retryDelay = 200)
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                // Execute the transaction
                return DB::transaction($callback);

            } catch (QueryException $e) {
                // Check if the error is a deadlock (MySQL error code 1213)
                if ($e->getCode() === '1213') {
                    $attempt++;

                    if ($attempt >= $maxRetries) {
                        // Throw the exception if max retries are reached
                        throw $e;
                    }

                    // Wait before retrying
                    usleep($retryDelay * 1000); // Convert milliseconds to microseconds
                } else {
                    // Re-throw exception if it's not a deadlock
                    throw $e;
                }
            }
        }
    }
}
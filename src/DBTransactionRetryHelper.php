<?php

namespace MysqlDeadlocks\RetryHelper;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class DBTransactionRetryHelper
{
    /**
     * Perform a database transaction with retry logic in case of deadlocks.
     *
     * @param callable $callback The transaction logic to execute.
     * @param int $maxRetries Number of times to retry on deadlock.
     * @param int $retryDelay Delay between retries in seconds.
     * @return mixed
     * @throws QueryException
     */
    public static function transactionWithRetry(callable $callback, int $maxRetries = 3, int $retryDelay = 2)
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                // Execute the transaction
                return DB::transaction($callback);

            } catch (QueryException $e) {
                // Check if the error is a deadlock (MySQL error code 1213)
                if ($e->getCode() == 1213 || $e->getCode() == 40001 || $e->errorInfo[1] == 1213) {
                    $attempt++;

                    if ($attempt >= $maxRetries) {
                        // Generate log when max retries are reached
                        generateLog([
                            'ExceptionName' => get_class($e),
                            'QueryException' => $e->getMessage(),
                            'url' => request()->getUri() ?? null,
                            'method' => request()->getMethod() ?? null,
                            'token' => request()->header()['authorization'] ?? null,
                            'userId' => request()->user()->id ?? null,
                            'trace' => getDebugBacktraceArray() ?? null,
                        ],'mysql-deadlocks-logs');
                        // Throw the exception if max retries are reached
                        throw $e;
                    }

                    // Wait before retrying
                    sleep($retryDelay); // Convert seconds to microseconds
                } else {
                    // Re-throw exception if it's not a deadlock
                    throw $e;
                }
            }
        }
    }
}
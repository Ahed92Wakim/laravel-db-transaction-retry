<?php

namespace MysqlDeadlocks\RetryHelper;

use Throwable;
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
     * @param string $logFileName
     * @return void
     * @throws QueryException
     * @throws Throwable
     */
    public static function transactionWithRetry(callable $callback, int $maxRetries = 5, int $retryDelay = 5, string $logFileName = 'mysql-deadlocks-log')
    {
        $attempt = 0;
        $throwable = null;
        $log = [];
        while ($attempt < $maxRetries) {
            try {
                // Execute the transaction
                $done = true;
                return DB::transaction($callback);

            } catch (QueryException $e) {
                $done = false;
                // Check if the error is a deadlock (MySQL error code 1213)
                if ($e->getCode() == 1213 || $e->getCode() == 40001 || $e->errorInfo[1] == 1213) {
                    $attempt++;
                    $log[] = [
                        'attempt' => $attempt,
                        'ExceptionName' => get_class($e),
                        'QueryException' => $e->getMessage(),
                        'url' => request()->getUri() ?? null,
                        'method' => request()->getMethod() ?? null,
                        'token' => request()->header()['authorization'] ?? null,
                        'userId' => request()->user()->id ?? null,
                        'trace' => getDebugBacktraceArray() ?? null,
                    ];

                    if ($attempt >= $maxRetries) {
                        // Generate log when max retries are reached
                        $log[] = [
                            'attempt' => $attempt,
                            'ExceptionName' => get_class($e),
                            'QueryException' => $e->getMessage(),
                            'url' => request()->getUri() ?? null,
                            'method' => request()->getMethod() ?? null,
                            'token' => request()->header()['authorization'] ?? null,
                            'userId' => request()->user()->id ?? null,
                            'trace' => getDebugBacktraceArray() ?? null,
                        ];
                        $throwable = $e;
                    }

                    // Wait before retrying
                    sleep($retryDelay);
                } else {
                    // Re-throw exception if it's not a deadlock
                    $throwable = $e;
                }
            } finally {
                if (is_null($throwable) and $done) {
                    if (count($log) > 0) {
                        generateLog($log[count($log) - 1], $logFileName, 'warning');
                    }
                } elseif (!is_null($throwable)) {
                    if (count($log) > 0) {
                        generateLog($log[count($log) - 1], $logFileName);
                    }
                    throw $throwable;
                }
            }
        }
    }
}

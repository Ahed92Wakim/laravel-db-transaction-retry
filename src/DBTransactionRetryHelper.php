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
     * @param string $logFileName The log file name
     * @return mixed
     * @throws QueryException
     * @throws Throwable
     */
    public static function transactionWithRetry(callable $callback, int $maxRetries = 3, int $retryDelay = 2, string $logFileName = 'mysql-deadlocks'): mixed
    {
        $attempt = 0;
        $throwable = null;
        $log = [];
        while ($attempt < $maxRetries) {
            try {
                // Execute the transaction
                $exceptionCatched = false;
                return DB::transaction($callback);

            } catch (QueryException $e) {
                $exceptionCatched = true;
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
                if (is_null($throwable) and !$exceptionCatched) {
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

        // If we exit the loop without returning, throw a generic runtime exception
        throw new \RuntimeException('Transaction with retry exhausted after ' . $maxRetries . ' attempts.');
    }

    protected static function isDeadlockOrSerializationError(QueryException $e): bool
    {
        // MySQL deadlock: driver error 1213; lock wait timeout: 1205 (often not retryable); SQLSTATE 40001 serialization failure
        $sqlState = $e->getCode(); // In Laravel, getCode often returns SQLSTATE (e.g., '40001')
        $driverErr = is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;

        return ($sqlState === '40001')
            || ($driverErr === 1213)
            || ($sqlState === 1213) // in case driver bubbles numeric
            ;
    }

    protected static function buildLogContext(QueryException $e, int $attempt): array
    {
        $requestData = [
            'url' => null,
            'method' => null,
            'token' => null,
            'userId' => null,
        ];

        try {
            // Only access request() when available (HTTP context)
            if (function_exists('request') && app()->bound('request')) {
                $req = request();
                $requestData['url'] = method_exists($req, 'getUri') ? $req->getUri() : null;
                $requestData['method'] = method_exists($req, 'getMethod') ? $req->getMethod() : null;
                $requestData['token'] = method_exists($req, 'header') ? ($req->header('authorization') ?? null) : null;
                $requestData['userId'] = method_exists($req, 'user') && $req->user() ? ($req->user()->id ?? null) : null;
            }
        } catch (Throwable) {
            // ignore request context errors for CLI/queue
        }

        return array_merge([
            'attempt' => $attempt,
            'errorInfo' => $e->errorInfo,
            'ExceptionName' => get_class($e),
            'QueryException' => $e->getMessage(),
            'trace' => getDebugBacktraceArray() ?? null,
        ], $requestData);
    }

    /**
     * @throws RandomException
     */
    protected static function backoffDelay(int $baseDelay, int $attempt): int
    {
        // Simple exponential backoff with jitter: baseDelay * 2^(attempt-1) +/- 25%
        $delay = max(1, (int)round($baseDelay * pow(2, max(0, $attempt - 1))));
        $jitter = max(0, (int)round($delay * 0.25));
        $min = max(1, $delay - $jitter);
        $max = $delay + $jitter;
        return random_int($min, $max);
    }
}

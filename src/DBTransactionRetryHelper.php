<?php

namespace MysqlDeadlocks\RetryHelper;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Random\RandomException;
use Throwable;

class DBTransactionRetryHelper
{
    /**
     * Perform a database transaction with retry logic in case of deadlocks.
     *
     * @param Closure $callback The transaction logic to execute.
     * @param int $maxRetries Number of times to retry on deadlock.
     * @param int $retryDelay Delay between retries in seconds.
     * @param string $logFileName The log file name
     * @param string $trxLabel The transaction label
     * @throws RandomException
     * @throws Throwable
     */
    public static function transactionWithRetry(Closure $callback, int $maxRetries = 3, int $retryDelay = 2, string $logFileName = 'database/mysql-deadlocks', string $trxLabel = ''): mixed
    {
        if (is_null($trxLabel)) {
            $trxLabel = '';
        }
        $attempt = 0;
        $log     = [];

        while ($attempt < $maxRetries) {
            // reset per-attempt flags to avoid stale values
            $throwable        = null;
            $exceptionCatched = false;
            $isDeadlock       = false;

            try {
                // Execute the transaction
                $trxLabel === '' || app()->instance('tx.label', $trxLabel);
                $result = DB::transaction($callback);

                return $result;

            } catch (QueryException $e) {
                $exceptionCatched = true;

                // Only deadlock/serialization *may* be retried and logged
                $isDeadlock = static::isDeadlockOrSerializationError($e);

                if ($isDeadlock) {
                    $attempt++;
                    $log[] = static::buildLogContext($e, $attempt, $maxRetries, $trxLabel);

                    if ($attempt >= $maxRetries) {
                        // exhausted retries — throw after logging below in finally
                        $throwable = $e;
                    } else {
                        // Exponential backoff with jitter
                        $delay = static::backoffDelay($retryDelay, $attempt);
                        sleep($delay);
                        continue; // retry
                    }
                } else {
                    // Non-deadlock: DO NOT log, just rethrow
                    $throwable = $e;
                }
            } finally {
                if (is_null($throwable) && !$exceptionCatched) {
                    // Success on first try, nothing to do.
                    // If you want to warn when there WERE previous retries that succeeded, keep this block:
                    if (count($log) > 0) {
                        // optional: downgrade to warning for eventual success after retries
                        generateLog($log[count($log) - 1], $logFileName, 'warning');
                    }
                } elseif (!is_null($throwable)) {
                    // We only log when it is a DEADLOCK and retries are exhausted.
                    if ($isDeadlock && count($log) > 0) {
                        generateLog($log[count($log) - 1], $logFileName);
                    }

                    // For NON-deadlock, nothing is logged — just throw.
                    throw $throwable;
                }
            }
        }

        throw new \RuntimeException('Transaction with retry exhausted after ' . $maxRetries . ' attempts.');
    }

    protected static function isDeadlockOrSerializationError(QueryException $e): bool
    {
        // MySQL deadlock: driver error 1213; lock wait timeout: 1205 (often not retryable); SQLSTATE 40001 serialization failure
        $sqlState  = $e->getCode(); // In Laravel, getCode often returns SQLSTATE (e.g., '40001')
        $driverErr = is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;

        return ($sqlState === '40001')
            || ($driverErr === 1213)
            || ($sqlState === 1213) // in case driver bubbles numeric
        ;
    }

    protected static function buildLogContext(QueryException $e, int $attempt, int $maxRetries, string $trxLabel): array
    {
        // Extract sql & bindings safely
        $sql      = method_exists($e, 'getSql') ? $e->getSql() : null;
        $bindings = method_exists($e, 'getBindings') ? $e->getBindings() : [];

        $connectionName = $e->getConnectionName();
        $conn           = DB::connection($connectionName);

        // if laravel version <= 11.x then getRawSql() is not available and we will do it manually
        $rawSql = method_exists($e, 'getRawSql') ? $e->getRawSql() : null;
        if (is_null($rawSql) && !is_null($sql) && !empty($bindings)) {
            $rawSql = $conn->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $bindings);
        }

        $requestData = [
            'url'    => null,
            'method' => null,
            'token'  => null,
            'userId' => null,
        ];

        try {
            if (function_exists('request') && app()->bound('request')) {
                $req                   = request();
                $requestData['url']    = method_exists($req, 'getUri') ? $req->getUri() : null;
                $requestData['method'] = method_exists($req, 'getMethod') ? $req->getMethod() : null;
                if (method_exists($req, 'header')) {
                    $auth                         = $req->header('authorization');
                    $requestData['authHeaderLen'] = $auth ? strlen($auth) : null;
                }
                $requestData['userId'] = method_exists($req, 'user') && $req->user() ? ($req->user()->id ?? null) : null;
            }
        } catch (Throwable) {
            // ignore
        }

        return array_merge($requestData, [
            'attempt'    => $attempt,
            'maxRetries' => $maxRetries,
            'trxLabel'   => $trxLabel,
            'errorInfo'  => $e->errorInfo,
            'rawSql'     => $rawSql,
            'connection' => $connectionName,
            'trace'      => getDebugBacktraceArray(),
        ]);
    }

    /**
     * @throws RandomException
     */
    protected static function backoffDelay(int $baseDelay, int $attempt): int
    {
        // Simple exponential backoff with jitter: baseDelay * 2^(attempt-1) +/- 25%
        $delay  = max(1, (int)round($baseDelay * pow(2, max(0, $attempt - 1))));
        $jitter = max(0, (int)round($delay * 0.25));
        $min    = max(1, $delay - $jitter);
        $max    = $delay + $jitter;

        return random_int($min, $max);
    }
}

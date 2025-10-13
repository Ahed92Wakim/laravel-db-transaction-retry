<?php

//
//namespace MysqlDeadlocks\RetryHelper;
//
//use Random\RandomException;
//use Throwable;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Database\QueryException;
//use Illuminate\Support\Facades\Log;
//use Illuminate\Support\Facades\App;
//
//class DBTransactionRetryHelperOld
//{
//    /**
//     * Perform a database transaction with retry logic in case of deadlocks.
//     *
//     * @param callable $callback The transaction logic to execute.
//     * @param int $maxRetries Number of times to retry on deadlock.
//     * @param int $retryDelay Delay between retries in seconds.
//     * @param string $logFileName The log channel name (config/logging.php)
//     * @param string $txLabel The transaction label.
//     * @return mixed
//     * @throws RandomException
//     * @throws Throwable
//     */
//    public static function transactionWithRetry(
//        callable $callback,
//        int      $maxRetries = 3,
//        int      $retryDelay = 2,
//        string   $logFileName = 'mysql-deadlocks',
//        string   $txLabel = ''
//    ): mixed
//    {
//        $attempt = 0;
//        while ($attempt < $maxRetries) {
//
//            try {
//                // Attempt the transaction
//                return DB::transaction($callback);
//
//            } catch (QueryException $e) {
//                $attempt++;
//
//                $isDeadlock = static::isDeadlockOrSerializationError($e);
//
//                // Always include URL/method/sql/bindings in context
//                $ctx = static::buildLogContext($e, $attempt);
//
//                if ($isDeadlock) {
//                    // ---------------------------
//                    // Case 1: deadlock attempt
//                    // ---------------------------
//                    static::logWithChannel(
//                        $logFileName,
//                        'warning',
//                        "[$txLabel] DB Deadlock Detected; Retrying (Attempts: $attempt/$maxRetries)",
//                        array_merge(['result' => 'retrying'], $ctx)
//                    );
//
//                    if ($attempt >= $maxRetries) {
//                        // ---------------------------
//                        // Case 2: exhausted retries
//                        // ---------------------------
//                        static::logWithChannel(
//                            $logFileName,
//                            'error',
//                            "[$txLabel] DB Deadlock; Retries Exhausted After (Attempts: $attempt/$maxRetries)",
//                            array_merge(['result' => 'failed'], $ctx)
//                        );
//                        throw $e;
//                    }
//
//                    // Exponential backoff with jitter
//                    $delay = static::backoffDelay($retryDelay, $attempt);
//                    sleep($delay);
//                    continue; // next loop
//                }
//
//                // ---------------------------
//                // Case 3: not a deadlock
//                // ---------------------------
//                static::logWithChannel(
//                    $logFileName,
//                    'error',
//                    "[$txLabel] DB non-Deadlock Error; (Attempts: $attempt/$maxRetries)",
//                    array_merge(['result' => 'non-deadlock'], $ctx)
//                );
//
//                throw $e; // propagate
//
//            } catch (Throwable $e) {
//                // Non-QueryException: log basic info & rethrow
//                $attempt++;
//                static::logWithChannel(
//                    $logFileName,
//                    'error',
//                    "[$txLabel] DB Threw non-QueryException; (Attempts: $attempt/$maxRetries)",
//                    [
//                        'attempt' => $attempt,
//                        'maxRetries' => $maxRetries,
//                        'exception' => get_class($e),
//                        'message' => $e->getMessage(),
//                        'trace' => static::safeTrace(),
//                    ]
//                );
//                throw $e;
//            }
//        }
//
//        // Should not reach here because we either return or throw inside the loop
//        throw new \RuntimeException("[$txLabel] Transaction with retry exhausted after: (attempts: $attempt/$maxRetries)");
//    }
//
//    protected static function isDeadlockOrSerializationError(QueryException $e): bool
//    {
//        // MySQL: 1213 = deadlock; 40001 = serialization failure (SQLSTATE)
//        // We intentionally DO NOT include 1205 (lock wait timeout) — treat as non-deadlock.
//        $sqlState = $e->getCode(); // Often SQLSTATE like '40001'
//        $driverErr = is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;
//
//        return ($sqlState === '40001')
//            || ($driverErr === 1213)
//            || ($sqlState === 1213);
//    }
//
//    protected static function buildLogContext(QueryException $e, int $attempt): array
//    {
//        // Extract sql & bindings safely
//        $sql = method_exists($e, 'getSql') ? $e->getSql() : null;
//        $bindings = method_exists($e, 'getBindings') ? $e->getBindings() : [];
//
//        // Try to read connection name
//        $connectionName = null;
//        try {
//            $connection = DB::connection();
//            $connectionName = $connection?->getName();
//        } catch (Throwable) {
//            // ignore
//        }
//
//        $requestData = [
//            'url' => null,
//            'method' => null,
//            'authHeaderLen' => null, // don't log sensitive tokens
//            'userId' => null,
//        ];
//
//        try {
//            if (function_exists('request') && app()->bound('request')) {
//                $req = request();
//                $requestData['url'] = method_exists($req, 'getUri') ? $req->getUri() : null;
//                $requestData['method'] = method_exists($req, 'getMethod') ? $req->getMethod() : null;
//                if (method_exists($req, 'header')) {
//                    $auth = $req->header('authorization');
//                    $requestData['authHeaderLen'] = $auth ? strlen($auth) : null;
//                }
//                $requestData['userId'] = method_exists($req, 'user') && $req->user() ? ($req->user()->id ?? null) : null;
//            }
//        } catch (Throwable) {
//            // ignore
//        }
//
//        return array_merge($requestData, [
//            'attempt' => $attempt,
//            'Exception' => get_class($e),
//            'message' => $e->getMessage(),
//            'sql' => $sql,
//            'bindings' => static::stringifyBindings($bindings),
//            'errorInfo' => $e->errorInfo,
//            'connection' => $connectionName,
//            'trace' => static::safeTrace(),
//        ]);
//    }
//
//    protected static function stringifyBindings(array $bindings): array
//    {
////        return array_map(function ($b) {
////            if ($b instanceof \DateTimeInterface) {
////                return $b->format('Y-m-d H:i:s.u');
////            }
////            if (is_object($b) || is_array($b)) {
////                return json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
////            }
////            return $b;
////        }, $bindings);
//        return array_map(function ($b) {
//            if ($b instanceof \DateTimeInterface) {
//                return $b->format('Y-m-d H:i:s.u');
//            }
//            if (is_object($b)) {
//                return '[object ' . get_class($b) . ']';
//            }
//            if (is_resource($b)) {
//                return '[resource]';
//            }
//            if (is_string($b)) {
//                // Trim very long strings to avoid log bloat
//                return mb_strlen($b) > 500 ? (mb_substr($b, 0, 500) . '…[+trimmed]') : $b;
//            }
//            if (is_array($b)) {
//                // Compact arrays
//                $json = @json_encode($b, JSON_UNESCAPED_UNICODE);
//
//                return $json !== false
//                    ? (mb_strlen($json) > 500 ? (mb_substr($json, 0, 500) . '…[+trimmed]') : $json)
//                    : '[array]';
//            }
//
//            return $b;
//        }, $bindings);
//    }
//
//    protected static function safeTrace(): array
//    {
//        try {
//            return collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15))
//                ->map(fn($f) => [
//                    'file' => $f['file'] ?? null,
//                    'line' => $f['line'] ?? null,
//                    'function' => $f['function'] ?? null,
//                    'class' => $f['class'] ?? null,
//                    'type' => $f['type'] ?? null,
//                ])->all();
//        } catch (Throwable) {
//            return [];
//        }
//    }
//
//    /**
//     * @throws RandomException
//     */
//    protected static function backoffDelay(int $baseDelay, int $attempt): int
//    {
//        // Simple exponential backoff with jitter: baseDelay * 2^(attempt-1) +/- 25%
//        $delay = max(1, (int)round($baseDelay * pow(2, max(0, $attempt - 1))));
//        $jitter = max(0, (int)round($delay * 0.25));
//        $min = max(1, $delay - $jitter);
//        $max = $delay + $jitter;
//        return random_int($min, $max);
//    }
//
//    protected static function logWithChannel(string $channel, string $level, string $message, array $context = []): void
//    {
//        $logger = Log::build([
//            'driver' => 'single',
//            'path' => storage_path("logs/{$channel}.log"),
//        ]);
//
//        // Normalize level
//        $level = strtolower($level);
//        if (!in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)) {
//            $level = 'info';
//        }
//
//        $logger->{$level}($message, $context);
//    }
//}

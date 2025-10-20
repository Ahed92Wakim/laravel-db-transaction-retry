<?php

namespace MysqlDeadlocks\RetryHelper\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MysqlDeadlocks\RetryHelper\Support\DeadlockLogWriter;
use MysqlDeadlocks\RetryHelper\Support\TraceFormatter;
use Random\RandomException;
use RuntimeException;
use Throwable;

class DeadlockTransactionRetrier
{
    /**
     * Run the supplied callback inside a transaction with retry logic for MySQL deadlocks and serialization errors.
     *
     * @param Closure $callback The transaction logic to execute.
     * @param int|null $maxRetries Number of times to retry on deadlock. Falls back to configuration.
     * @param int|null $retryDelay Delay between retries in seconds (base for backoff). Falls back to configuration.
     * @param string|null $logFileName The log file name. Falls back to configuration.
     * @param string $trxLabel The transaction label.
     * @throws RandomException
     * @throws Throwable
     */
    public static function runWithRetry(
        Closure $callback,
        ?int $maxRetries = null,
        ?int $retryDelay = null,
        ?string $logFileName = null,
        string $trxLabel = ''
    ): mixed {
        $config = function_exists('config') ? config('mysql-deadlock-retry', []) : [];

        $maxRetries  ??= (int) ($config['max_retries'] ?? 3);
        $retryDelay  ??= (int) ($config['retry_delay'] ?? 2);
        $logFileName ??= (string) ($config['log_file_name'] ?? 'database/mysql-deadlocks');

        $maxRetries = max(1, $maxRetries);
        $retryDelay = max(1, $retryDelay);

        $trxLabel = $trxLabel ?? '';

        $attempt    = 0;
        $logEntries = [];

        while ($attempt < $maxRetries) {
            $throwable        = null;
            $exceptionCaught  = false;
            $shouldRetryError = false;

            try {
                // Expose the transaction label if the app wants to read it during the callback.
                $trxLabel === '' || app()->instance('tx.label', $trxLabel);

                return DB::transaction($callback);
            } catch (QueryException $e) {
                $exceptionCaught  = true;
                $shouldRetryError = static::shouldRetry($e);

                if ($shouldRetryError) {
                    $attempt++;
                    $logEntries[] = static::makeRetryContext($e, $attempt, $maxRetries, $trxLabel);

                    if ($attempt >= $maxRetries) {
                        $throwable = $e;
                    } else {
                        static::pause(static::nextBackoffInterval($retryDelay, $attempt));
                        continue;
                    }
                } else {
                    $throwable = $e;
                }
            } finally {
                static::logOutcome(
                    $logEntries,
                    $logFileName,
                    $throwable,
                    $exceptionCaught,
                    $shouldRetryError
                );

                if (! is_null($throwable)) {
                    throw $throwable;
                }
            }
        }

        throw new RuntimeException('Transaction with retry exhausted after ' . $maxRetries . ' attempts.');
    }

    protected static function shouldRetry(QueryException $e): bool
    {
        return static::isDeadlock($e) || static::isSerializationFailure($e);
    }

    protected static function isDeadlock(QueryException $e): bool
    {
        $driverErr = is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;
        $sqlState  = $e->getCode();

        return (int) $driverErr === 1213 || (int) $sqlState === 1213;
    }

    protected static function isSerializationFailure(QueryException $e): bool
    {
        return $e->getCode() === '40001';
    }

    protected static function makeRetryContext(QueryException $e, int $attempt, int $maxRetries, string $trxLabel): array
    {
        $sql      = method_exists($e, 'getSql') ? $e->getSql() : null;
        $bindings = method_exists($e, 'getBindings') ? $e->getBindings() : [];

        $connectionName = $e->getConnectionName();
        $conn           = DB::connection($connectionName);

        $rawSql = method_exists($e, 'getRawSql') ? $e->getRawSql() : null;
        if (is_null($rawSql) && ! is_null($sql) && ! empty($bindings)) {
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
                $requestData['userId'] = method_exists($req, 'user') && $req->user()
                    ? ($req->user()->id ?? null)
                    : null;
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
            'trace'      => TraceFormatter::snapshot(),
        ]);
    }

    /**
     * @throws RandomException
     */
    protected static function nextBackoffInterval(int $baseDelay, int $attempt): int
    {
        $delay  = max(1, (int) round($baseDelay * pow(2, max(0, $attempt - 1))));
        $jitter = max(0, (int) round($delay * 0.25));
        $min    = max(1, $delay - $jitter);
        $max    = $delay + $jitter;

        return random_int($min, $max);
    }

    protected static function logOutcome(
        array $logEntries,
        string $logFileName,
        ?Throwable $throwable,
        bool $exceptionCaught,
        bool $shouldRetryError
    ): void {
        $levels = static::configuredLogLevels();

        if (is_null($throwable) && ! $exceptionCaught) {
            if (count($logEntries) > 0) {
                $entry                = $logEntries[count($logEntries) - 1];
                $entry['retryStatus'] = 'success';

                DeadlockLogWriter::write($entry, $logFileName, $levels['success']);
            }

            return;
        }

        if (! is_null($throwable) && $shouldRetryError && count($logEntries) > 0) {
            $entry                = $logEntries[count($logEntries) - 1];
            $entry['retryStatus'] = 'failure';

            DeadlockLogWriter::write($entry, $logFileName, $levels['failure']);
        }

        // Non-retryable errors rethrow outside this helper; only log when retries are exhausted.
    }

    protected static function pause(int $seconds): void
    {
        $overriddenSleep = 'MysqlDeadlocks\\RetryHelper\\sleep';

        if (function_exists($overriddenSleep)) {
            $overriddenSleep($seconds);

            return;
        }

        sleep($seconds);
    }

    protected static function configuredLogLevels(): array
    {
        $defaults = [
            'success' => 'warning',
            'failure' => 'error',
        ];

        if (! function_exists('config')) {
            return $defaults;
        }

        $levels = config('mysql-deadlock-retry.logging.levels', []);

        if (! is_array($levels)) {
            return $defaults;
        }

        return [
            'success' => static::normaliseLogLevel($levels['success'] ?? null, $defaults['success']),
            'failure' => static::normaliseLogLevel($levels['failure'] ?? null, $defaults['failure']),
        ];
    }

    protected static function normaliseLogLevel(?string $level, string $fallback): string
    {
        $candidate = is_string($level) ? strtolower(trim($level)) : null;

        return $candidate !== '' ? $candidate : $fallback;
    }
}

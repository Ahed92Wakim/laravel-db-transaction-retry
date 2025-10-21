<?php

namespace DatabaseTransactions\RetryHelper\Services;

use Closure;
use DatabaseTransactions\RetryHelper\Support\TraceFormatter;
use DatabaseTransactions\RetryHelper\Support\TransactionRetryLogWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Random\RandomException;
use RuntimeException;
use Throwable;

class TransactionRetrier
{
    /**
     * Run the supplied callback inside a transaction with retry logic for configured database errors.
     *
     * @param Closure $callback The transaction logic to execute.
     * @param int|null $maxRetries Maximum number of retries. Falls back to configuration.
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
        $config = function_exists('config') ? config('database-transaction-retry', []) : [];

        $maxRetries  ??= (int) ($config['max_retries'] ?? 3);
        $retryDelay  ??= (int) ($config['retry_delay'] ?? 2);
        $logFileName ??= (string) ($config['log_file_name'] ?? 'database/transaction-retries');

        $maxRetries = max(1, $maxRetries);
        $retryDelay = max(1, $retryDelay);
        $trxLabel   = $trxLabel ?? '';

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
            } catch (Throwable $exception) {
                $exceptionCaught  = true;
                $shouldRetryError = static::shouldRetry($exception);

                if ($shouldRetryError) {
                    $attempt++;
                    $logEntries[] = static::makeRetryContext($exception, $attempt, $maxRetries, $trxLabel);

                    if ($attempt >= $maxRetries) {
                        $throwable = $exception;
                    } else {
                        static::pause(static::nextBackoffInterval($retryDelay, $attempt));
                        continue;
                    }
                } else {
                    $throwable = $exception;
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

    protected static function shouldRetry(Throwable $throwable): bool
    {
        $config = function_exists('config') ? config('database-transaction-retry.retryable_exceptions', []) : [];

        if (! is_array($config)) {
            $config = [];
        }

        $retryableClasses = array_filter(
            array_map('trim', is_array($config['classes'] ?? null) ? $config['classes'] : []),
            static fn ($class) => $class !== ''
        );

        foreach ($retryableClasses as $class) {
            if (class_exists($class) && $throwable instanceof $class) {
                return true;
            }
        }

        if ($throwable instanceof QueryException) {
            return static::isRetryableQueryException($throwable, $config);
        }

        return false;
    }

    protected static function isRetryableQueryException(QueryException $exception, array $config): bool
    {
        $sqlStates = is_array($config['sql_states'] ?? null) ? $config['sql_states'] : [];
        $sqlStates = array_map(static fn ($state) => strtoupper((string) $state), $sqlStates);

        $driverCodes = is_array($config['driver_error_codes'] ?? null) ? $config['driver_error_codes'] : [];
        $driverCodes = array_map(static fn ($code) => (int) $code, $driverCodes);

        $sqlState  = strtoupper((string) $exception->getCode());
        $driverErr = is_array($exception->errorInfo ?? null) && isset($exception->errorInfo[1])
            ? (int) $exception->errorInfo[1]
            : null;

        if (in_array($sqlState, $sqlStates, true)) {
            return true;
        }

        if (! is_null($driverErr) && in_array($driverErr, $driverCodes, true)) {
            return true;
        }

        return false;
    }

    protected static function makeRetryContext(Throwable $throwable, int $attempt, int $maxRetries, string $trxLabel): array
    {
        $context = [
            'attempt'        => $attempt,
            'maxRetries'     => $maxRetries,
            'trxLabel'       => $trxLabel,
            'exceptionClass' => get_class($throwable),
        ];

        if ($throwable instanceof QueryException) {
            $sql      = method_exists($throwable, 'getSql') ? $throwable->getSql() : null;
            $bindings = method_exists($throwable, 'getBindings') ? $throwable->getBindings() : [];

            $connectionName        = $throwable->getConnectionName();
            $context['connection'] = $connectionName;

            $conn = DB::connection($connectionName);

            $rawSql = method_exists($throwable, 'getRawSql') ? $throwable->getRawSql() : null;
            if (is_null($rawSql) && ! is_null($sql) && ! empty($bindings)) {
                $rawSql = $conn->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $bindings);
            }

            $context['rawSql']    = $rawSql;
            $context['errorInfo'] = $throwable->errorInfo;
            $context['sqlState']  = isset($throwable->errorInfo[0])
                ? (string) $throwable->errorInfo[0]
                : (method_exists($throwable, 'getCode') ? (string) $throwable->getCode() : null);
            $context['driverCode'] = isset($throwable->errorInfo[1]) ? (int) $throwable->errorInfo[1] : null;
        }

        $context['trace'] = TraceFormatter::snapshot();

        try {
            $context += static::requestSnapshot();
        } catch (Throwable) {
            // ignore
        }

        return $context;
    }

    protected static function requestSnapshot(): array
    {
        $data = [
            'url'    => null,
            'method' => null,
            'token'  => null,
            'userId' => null,
        ];

        if (! function_exists('request') || ! app()->bound('request')) {
            return $data;
        }

        $request = request();

        $data['url']    = method_exists($request, 'getUri') ? $request->getUri() : null;
        $data['method'] = method_exists($request, 'getMethod') ? $request->getMethod() : null;

        if (method_exists($request, 'header')) {
            $auth                  = $request->header('authorization');
            $data['authHeaderLen'] = $auth ? strlen($auth) : null;
        }

        $data['userId'] = method_exists($request, 'user') && $request->user()
            ? ($request->user()->id ?? null)
            : null;

        return $data;
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

                TransactionRetryLogWriter::write($entry, $logFileName, $levels['success']);
            }

            return;
        }

        if (! is_null($throwable) && $shouldRetryError && count($logEntries) > 0) {
            $entry                = $logEntries[count($logEntries) - 1];
            $entry['retryStatus'] = 'failure';

            TransactionRetryLogWriter::write($entry, $logFileName, $levels['failure']);
        }

        // Non-retryable errors rethrow outside this helper; only log when retries are exhausted.
    }

    protected static function pause(int $seconds): void
    {
        $overriddenSleep = 'DatabaseTransactions\\RetryHelper\\sleep';

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

        $levels = config('database-transaction-retry.logging.levels', []);

        if (! is_array($levels)) {
            return $defaults;
        }

        return [
            'success' => static::normalizeLogLevel($levels['success'] ?? null, $defaults['success']),
            'failure' => static::normalizeLogLevel($levels['failure'] ?? null, $defaults['failure']),
        ];
    }

    protected static function normalizeLogLevel(?string $level, string $fallback): string
    {
        $candidate = is_string($level) ? strtolower(trim($level)) : null;

        return $candidate !== '' ? $candidate : $fallback;
    }
}

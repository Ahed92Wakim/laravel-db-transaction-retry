<?php

namespace DatabaseTransactions\RetryHelper\Services;

use Closure;
use DatabaseTransactions\RetryHelper\Enums\LogLevel;
use DatabaseTransactions\RetryHelper\Enums\RetryStatus;
use DatabaseTransactions\RetryHelper\Support\BindingStringifier;
use DatabaseTransactions\RetryHelper\Support\RetryToggle;
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
        $config   = function_exists('config') ? config('database-transaction-retry', []) : [];
        $config   = is_array($config) ? $config : [];
        $trxLabel = $trxLabel ?: '';

        if (! RetryToggle::isEnabled($config)) {
            static::exposeTransactionLabel($trxLabel);

            return DB::transaction($callback);
        }

        $maxRetries  ??= (int) ($config['max_retries'] ?? 3);
        $retryDelay  ??= (int) ($config['retry_delay'] ?? 2);
        $logFileName ??= (string) ($config['log_file_name'] ?? 'database/transaction-retries');

        $maxRetries = max(1, $maxRetries);
        $retryDelay = max(1, $retryDelay);
        $trxLabel   = $trxLabel ?? '';

        $retryGroupId = static::generateRetryGroupId();
        $attempt      = 0;
        $logEntries   = [];

        while ($attempt < $maxRetries) {
            $throwable        = null;
            $exceptionCaught  = false;
            $shouldRetryError = false;

            try {
                static::applyLockWaitTimeout($config);

                // Expose the transaction label if the app wants to read it during the callback.
                static::exposeTransactionLabel($trxLabel);

                $result = DB::transaction($callback);

                static::logOutcome(
                    $logEntries,
                    $logFileName,
                    null,
                    false,
                    false
                );

                return $result;
            } catch (Throwable $exception) {
                $exceptionCaught  = true;
                $shouldRetryError = static::shouldRetry($exception);

                if ($shouldRetryError) {
                    $attempt++;
                    $entry        = static::makeRetryContext($exception, $attempt, $maxRetries, $trxLabel, $retryGroupId);
                    $logEntries[] = $entry;

                    static::logAttempt($entry, $logFileName);

                    if ($attempt >= $maxRetries) {
                        $throwable = $exception;
                    } else {
                        static::logOutcome(
                            $logEntries,
                            $logFileName,
                            null,
                            true,
                            true
                        );

                        static::pause(static::nextBackoffInterval($retryDelay, $attempt));
                        continue;
                    }
                } else {
                    $throwable = $exception;
                }

                static::logOutcome(
                    $logEntries,
                    $logFileName,
                    $throwable,
                    $exceptionCaught,
                    $shouldRetryError
                );

                throw $throwable;
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

    protected static function makeRetryContext(
        Throwable $throwable,
        int $attempt,
        int $maxRetries,
        string $trxLabel,
        string $retryGroupId
    ): array {
        $context = [
            'attempt'        => $attempt,
            'maxRetries'     => $maxRetries,
            'trxLabel'       => $trxLabel,
            'retryGroupId'   => $retryGroupId,
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
            $context['sql']       = $sql;
            $context['bindings']  = BindingStringifier::forLogs($bindings);
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
            'url'       => null,
            'method'    => null,
            'routeName' => null,
            'token'     => null,
            'userId'    => null,
            'userType'  => null,
        ];

        if (! function_exists('request') || ! app()->bound('request')) {
            return $data;
        }

        $request = request();

        $data['method'] = method_exists($request, 'getMethod') ? $request->getMethod() : null;

        $data['url'] = null;
        if (method_exists($request, 'route')) {
            $route = $request->route();
            if (is_object($route) && method_exists($route, 'uri')) {
                $data['url'] = $route->uri();
            }
        }

        if (method_exists($request, 'route')) {
            $route = $request->route();
            if (is_object($route) && method_exists($route, 'getName')) {
                $data['routeName'] = $route->getName();
            } elseif (is_string($route)) {
                $data['routeName'] = $route;
            }
        }

        if (method_exists($request, 'header')) {
            $auth                  = $request->header('authorization');
            $data['authHeaderLen'] = $auth ? strlen($auth) : null;
        }

        $user = method_exists($request, 'user') ? $request->user() : null;
        if (is_object($user)) {
            $data['userType'] = get_class($user);

            if (method_exists($user, 'getAuthIdentifier')) {
                $data['userId'] = $user->getAuthIdentifier();
            } elseif (isset($user->id)) {
                $data['userId'] = $user->id;
            }
        }

        return $data;
    }

    protected static function generateRetryGroupId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return uniqid('trx_', true);
        }
    }

    /**
     * Calculate the next backoff interval with jitter and a safety cap.
     *
     * @throws RandomException
     */
    protected static function nextBackoffInterval(int $baseDelay, int $attempt): int
    {
        $delay  = max(1, (int) round($baseDelay * pow(2, max(0, $attempt - 1))));
        $jitter = max(0, (int) round($delay * 0.25));
        $min    = max(1, $delay - $jitter);
        $max    = $delay + $jitter;

        $interval = random_int($min, $max);

        // Safety cap: don't allow a single retry delay to exceed 60 seconds
        // to stay within reasonable PHP execution limits.
        return min(60, $interval);
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
                $entry['retryStatus'] = RetryStatus::Success->value;

                TransactionRetryLogWriter::write($entry, $logFileName, $levels['success']);
            }

            return;
        }

        if (! is_null($throwable) && $shouldRetryError && count($logEntries) > 0) {
            $entry                = $logEntries[count($logEntries) - 1];
            $entry['retryStatus'] = RetryStatus::Failure->value;

            TransactionRetryLogWriter::write($entry, $logFileName, $levels['failure']);
        }

        // Non-retryable errors rethrow outside this helper; only log when retries are exhausted.
    }

    protected static function logAttempt(array $entry, string $logFileName): void
    {
        if (! static::isDatabaseLoggingEnabled()) {
            return;
        }

        $entry['retryStatus'] = RetryStatus::Attempt->value;

        TransactionRetryLogWriter::write(
            $entry,
            $logFileName,
            static::configuredAttemptLogLevel()
        );
    }

    protected static function isDatabaseLoggingEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        $logging = config('database-transaction-retry.logging', []);
        if (! is_array($logging)) {
            return true;
        }

        $driver = strtolower(trim((string) ($logging['driver'] ?? 'database')));

        return $driver === '' || $driver === 'database' || $driver === 'db';
    }

    protected static function pause(int $seconds): void
    {
        $overriddenSleep = 'DatabaseTransactions\\RetryHelper\\sleep';

        if (function_exists($overriddenSleep)) {
            // @phpstan-ignore-next-line
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

    protected static function configuredAttemptLogLevel(): string
    {
        $default = 'warning';

        if (! function_exists('config')) {
            return $default;
        }

        $levels = config('database-transaction-retry.logging.levels', []);

        if (! is_array($levels)) {
            return $default;
        }

        return static::normalizeLogLevel($levels['attempt'] ?? null, $default);
    }

    protected static function normalizeLogLevel(?string $level, string $fallback): string
    {
        $normalizedFallback = LogLevel::normalize($fallback, LogLevel::Error->value);

        return LogLevel::normalize($level, $normalizedFallback);
    }

    protected static function applyLockWaitTimeout(array $config): void
    {
        $seconds = $config['lock_wait_timeout_seconds'] ?? null;

        if (! static::isLockWaitRetryEnabled($config)) {
            return;
        }

        if (is_null($seconds)) {
            return;
        }

        if (is_string($seconds) && $seconds === '') {
            return;
        }

        $seconds = (int) $seconds;

        if ($seconds < 1) {
            return;
        }

        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = ?', [$seconds]);
        } catch (Throwable) {
            // Silently ignore when the underlying driver does not support this option.
        }
    }

    protected static function isLockWaitRetryEnabled(array $config): bool
    {
        $retryable = is_array($config['retryable_exceptions'] ?? null)
            ? $config['retryable_exceptions']
            : [];

        $driverCodes = is_array($retryable['driver_error_codes'] ?? null)
            ? array_map(static fn ($code) => (int) $code, $retryable['driver_error_codes'])
            : [];

        return in_array(1205, $driverCodes, true);
    }

    protected static function exposeTransactionLabel(string $trxLabel): void
    {
        if ($trxLabel === '') {
            return;
        }

        if (! function_exists('app')) {
            return;
        }

        app()->instance('tx.label', $trxLabel);
    }
}

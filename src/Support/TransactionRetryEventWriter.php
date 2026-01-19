<?php

namespace DatabaseTransactions\RetryHelper\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionRetryEventWriter
{
    public static function write(array $payload, string $level = 'error'): void
    {
        static::writeToDatabase($payload, $level);
    }

    protected static function writeToDatabase(array $payload, string $level): void
    {
        if (! class_exists(DB::class)) {
            return;
        }

        $context = is_array($payload) ? $payload : ['message' => (string) $payload];
        $levels  = static::configuredLevels();

        $normalizedLevel = static::normalizeLevel($level, $levels['failure']);
        $status          = strtolower((string) ($context['retryStatus'] ?? ''));
        $allowedStatuses = ['success', 'failure', 'attempt'];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = $normalizedLevel === $levels['success'] ? 'success' : 'failure';
        }

        $attempts = (int) ($context['attempt'] ?? 0);
        $max      = (int) ($context['maxRetries'] ?? 0);
        $label    = (string) ($context['trxLabel'] ?? '');

        $exceptionClass = (string) ($context['exceptionClass'] ?? '');
        $sqlState       = strtoupper((string) ($context['sqlState'] ?? ''));
        $driverCode     = $context['driverCode'] ?? null;
        $connection     = $context['connection'] ?? null;
        $rawSql         = $context['rawSql']     ?? null;
        $errorInfo      = $context['errorInfo']  ?? null;

        $method        = $context['method']        ?? null;
        $routeName     = $context['routeName']     ?? ($context['route_name'] ?? null);
        $url           = $context['url']           ?? null;
        $userId        = $context['userId']        ?? null;
        $authHeaderLen = $context['authHeaderLen'] ?? null;

        $userIdValue        = ! is_numeric($userId) ? null : (int) $userId;
        $authHeaderLenValue = ! is_numeric($authHeaderLen) ? null : (int) $authHeaderLen;

        $occurredAt = function_exists('now') ? now() : date('Y-m-d H:i:s');

        $routeHash = static::hashFromParts([$method, $routeName, $url]);
        $queryHash = static::hashFromParts([$rawSql]);
        $eventHash = static::hashFromParts([
            $status,
            $normalizedLevel,
            $attempts,
            $max,
            $label,
            $exceptionClass,
            $sqlState,
            $driverCode,
            $connection,
            $rawSql,
            $method,
            $url,
            $routeName,
            $userId,
        ]);

        $contextPayload = $context;
        foreach ([
            'attempt',
            'maxRetries',
            'trxLabel',
            'exceptionClass',
            'sqlState',
            'driverCode',
            'connection',
            'rawSql',
            'errorInfo',
            'method',
            'routeName',
            'route_name',
            'url',
            'userId',
            'authHeaderLen',
            'retryStatus',
        ] as $key) {
            unset($contextPayload[$key]);
        }

        $row = [
            'occurred_at'     => $occurredAt,
            'retry_status'    => $status,
            'log_level'       => $normalizedLevel,
            'attempt'         => $attempts,
            'max_retries'     => $max,
            'trx_label'       => $label          !== '' ? $label : null,
            'exception_class' => $exceptionClass !== '' ? $exceptionClass : null,
            'sql_state'       => $sqlState       !== '' ? $sqlState : null,
            'driver_code'     => is_null($driverCode) ? null : (int) $driverCode,
            'connection'      => is_null($connection) ? null : (string) $connection,
            'raw_sql'         => is_null($rawSql) ? null : (string) $rawSql,
            'error_info'      => static::encodeJson($errorInfo),
            'method'          => is_null($method) ? null : (string) $method,
            'route_name'      => is_null($routeName) ? null : (string) $routeName,
            'url'             => is_null($url) ? null : (string) $url,
            'user_id'         => $userIdValue,
            'auth_header_len' => $authHeaderLenValue,
            'route_hash'      => $routeHash,
            'query_hash'      => $queryHash,
            'event_hash'      => $eventHash,
            'context'         => static::encodeJson($contextPayload),
            'created_at'      => $occurredAt,
            'updated_at'      => $occurredAt,
        ];

        $table = static::loggingTable();
        if ($table === '') {
            return;
        }

        try {
            DB::table($table)->insert($row);
        } catch (Throwable) {
            // Never block transaction flow if persistence fails.
        }
    }

    protected static function loggingTable(): string
    {
        $default = 'transaction_retry_events';

        if (! function_exists('config')) {
            return $default;
        }

        $config = config('database-transaction-retry.logging', []);
        if (! is_array($config)) {
            return $default;
        }

        $table = trim((string) ($config['table'] ?? $default));

        return $table === '' ? $default : $table;
    }

    protected static function hashFromParts(array $parts): ?string
    {
        $string = implode('|', array_map(static fn ($part) => is_scalar($part) ? (string) $part : '', $parts));

        $string = trim($string, '|');

        if ($string === '') {
            return null;
        }

        return hash('sha256', $string);
    }

    protected static function encodeJson(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }

    protected static function configuredLevels(): array
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
            'success' => static::normalizeLevel($levels['success'] ?? null, $defaults['success']),
            'failure' => static::normalizeLevel($levels['failure'] ?? null, $defaults['failure']),
        ];
    }

    protected static function normalizeLevel(?string $level, string $fallback): string
    {
        if (! is_string($level)) {
            return $fallback;
        }

        $candidate = strtolower(trim($level));

        return $candidate !== '' ? $candidate : $fallback;
    }
}

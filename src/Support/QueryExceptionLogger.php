<?php

namespace DatabaseTransactions\RetryHelper\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueryExceptionLogger
{
    private static bool $isReporting = false;

    public static function report(QueryException $exception): void
    {
        if (! static::isEnabled()) {
            return;
        }

        if (static::$isReporting) {
            return;
        }

        if (! static::canAccessDatabase()) {
            return;
        }

        static::$isReporting = true;

        try {
            static::persist($exception);
        } catch (Throwable) {
            // Never allow logging failures to interfere with exception handling.
        } finally {
            static::$isReporting = false;
        }
    }

    protected static function persist(QueryException $exception): void
    {
        $config         = static::resolveConfig();
        $table          = $config['table'];
        $logConnection  = $config['connection'];
        $exceptionClass = get_class($exception);

        $errorInfo  = is_array($exception->errorInfo ?? null) ? $exception->errorInfo : null;
        $sqlState   = isset($errorInfo[0]) ? (string) $errorInfo[0] : (string) $exception->getCode();
        $driverCode = isset($errorInfo[1]) && is_numeric($errorInfo[1]) ? (int) $errorInfo[1] : null;

        $sql      = method_exists($exception, 'getSql') ? $exception->getSql() : null;
        $bindings = method_exists($exception, 'getBindings') ? (array) $exception->getBindings() : [];

        $connectionName = $exception->getConnectionName();
        $rawSql         = static::resolveRawSql($exception, $connectionName, $sql, $bindings);

        $request = static::requestSnapshot();
        $trace   = TraceFormatter::snapshot();

        $occurredAt = function_exists('now') ? now() : date('Y-m-d H:i:s');

        $eventHash = static::hashFromParts([
            $exceptionClass,
            strtoupper($sqlState),
            $driverCode,
            $connectionName,
            $rawSql,
            $request['method'],
            $request['route_name'],
            $request['url'],
            $request['user_id'],
        ]);

        $context = [
            'message' => $exception->getMessage(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ];

        $row = [
            'occurred_at'      => $occurredAt,
            'exception_class'  => $exceptionClass,
            'sql_state'        => strtoupper($sqlState),
            'driver_code'      => $driverCode,
            'connection'       => $connectionName,
            'sql'              => $sql,
            'raw_sql'          => $rawSql,
            'bindings'         => static::encodeJson(BindingStringifier::forLogs($bindings)),
            'error_message'    => $exception->getMessage(),
            'error_info'       => static::encodeJson($errorInfo),
            'method'           => $request['method'],
            'route_name'       => $request['route_name'],
            'url'              => $request['url'],
            'ip_address'       => $request['ip_address'],
            'user_type'        => $request['user_type'],
            'user_id'          => $request['user_id'],
            'auth_header_len'  => $request['auth_header_len'],
            'auth_header_hash' => $request['auth_header_hash'],
            'trace'            => static::encodeJson($trace),
            'event_hash'       => $eventHash,
            'context'          => static::encodeJson($context),
            'created_at'       => $occurredAt,
            'updated_at'       => $occurredAt,
        ];

        if ($table === '') {
            return;
        }

        $db = $logConnection ? DB::connection($logConnection) : DB::connection();

        $db->table($table)->insert($row);
    }

    protected static function resolveRawSql(
        QueryException $exception,
        ?string $connectionName,
        ?string $sql,
        array $bindings
    ): ?string {
        $rawSql = method_exists($exception, 'getRawSql') ? $exception->getRawSql() : null;

        if (! is_null($rawSql)) {
            return $rawSql;
        }

        if (is_null($sql) || $bindings === []) {
            return $sql;
        }

        try {
            $connection = DB::connection($connectionName);

            return $connection->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $bindings);
        } catch (Throwable) {
            return $sql;
        }
    }

    protected static function requestSnapshot(): array
    {
        $data = [
            'method'           => null,
            'route_name'       => null,
            'url'              => null,
            'ip_address'       => null,
            'user_id'          => null,
            'user_type'        => null,
            'auth_header_len'  => null,
            'auth_header_hash' => null,
        ];

        if (! function_exists('request') || ! function_exists('app') || ! app()->bound('request')) {
            return $data;
        }

        $request = request();

        if (method_exists($request, 'getMethod')) {
            $data['method'] = $request->getMethod();
        }

        if (method_exists($request, 'route')) {
            $route = $request->route();

            if (is_object($route) && method_exists($route, 'uri')) {
                $data['url'] = $route->uri();
            }
        }

        if (method_exists($request, 'route')) {
            $route = $request->route();

            if (is_object($route) && method_exists($route, 'getName')) {
                $data['route_name'] = $route->getName();
            } elseif (is_string($route)) {
                $data['route_name'] = $route;
            }
        }

        if (method_exists($request, 'ip')) {
            $data['ip_address'] = $request->ip();
        }

        if (method_exists($request, 'header')) {
            $auth = $request->header('authorization');

            if (is_string($auth) && $auth !== '') {
                $data['auth_header_len']  = strlen($auth);
                $data['auth_header_hash'] = hash('sha256', $auth);
            }
        }

        $user = method_exists($request, 'user') ? $request->user() : null;

        if (is_object($user)) {
            $data['user_type'] = get_class($user);

            if (method_exists($user, 'getAuthIdentifier')) {
                $data['user_id'] = (string) $user->getAuthIdentifier();
            } elseif (isset($user->id) && (is_scalar($user->id) || (is_object($user->id) && method_exists($user->id, '__toString')))) {
                $data['user_id'] = (string) $user->id;
            }
        }

        return $data;
    }

    protected static function resolveConfig(): array
    {
        $defaults = [
            'table'      => 'db_exceptions',
            'connection' => null,
            'enabled'    => true,
        ];

        if (! function_exists('config')) {
            return $defaults;
        }

        $config = config('database-transaction-retry.exception_logging', []);

        if (! is_array($config)) {
            return $defaults;
        }

        $table = trim((string) ($config['table'] ?? $defaults['table']));

        return [
            'table'      => $table === '' ? $defaults['table'] : $table,
            'connection' => isset($config['connection']) && $config['connection'] !== ''
                ? (string) $config['connection']
                : null,
            'enabled' => $config['enabled'] ?? $defaults['enabled'],
        ];
    }

    protected static function isEnabled(): bool
    {
        $config = static::resolveConfig();

        return ! RetryToggle::isExplicitlyDisabledValue($config['enabled']);
    }

    protected static function canAccessDatabase(): bool
    {
        if (! function_exists('app')) {
            return false;
        }

        try {
            return app()->bound('db');
        } catch (Throwable) {
            return false;
        }
    }

    protected static function encodeJson(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
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
}

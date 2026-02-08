<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DatabaseTransactions\RetryHelper\Contracts\LogDriverInterface;
use DatabaseTransactions\RetryHelper\Support\LogDrivers\DatabaseLogDriver;
use DatabaseTransactions\RetryHelper\Support\LogDrivers\FileLogDriver;

class TransactionRetryLogWriter
{
    /**
     * Map of available drivers.
     *
     * @var array<string, class-string<LogDriverInterface>>
     */
    protected static array $drivers = [
        'database' => DatabaseLogDriver::class,
        'db'       => DatabaseLogDriver::class,
        'log'      => FileLogDriver::class,
        'file'     => FileLogDriver::class,
    ];

    /**
     * Write the log entry using the configured driver.
     */
    public static function write(array $payload, string $logFileName, string $level = 'error'): void
    {
        $driverName  = static::loggingDriver();
        $driverClass = static::$drivers[$driverName] ?? FileLogDriver::class;

        /** @var LogDriverInterface $driver */
        $driver = new $driverClass();

        $driver->write($payload, $logFileName, $level);
    }

    /**
     * Get the configured logging driver name.
     */
    protected static function loggingDriver(): string
    {
        if (! function_exists('config')) {
            return 'log';
        }

        $config = config('database-transaction-retry.logging', []);
        if (! is_array($config)) {
            return 'database';
        }

        $driver = strtolower(trim((string) ($config['driver'] ?? 'database')));

        return $driver === '' ? 'database' : $driver;
    }

    /**
     * Register a custom log driver.
     *
     * @param class-string<LogDriverInterface> $driverClass
     */
    public static function extend(string $name, string $driverClass): void
    {
        static::$drivers[strtolower($name)] = $driverClass;
    }
}

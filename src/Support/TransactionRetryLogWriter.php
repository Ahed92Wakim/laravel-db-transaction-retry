<?php

namespace DatabaseTransactions\RetryHelper\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class TransactionRetryLogWriter
{
    public static function write(array $payload, string $logFileName, string $level = 'error'): void
    {
        $logger = static::resolveLogger($logFileName);

        $levels = static::configuredLevels();

        $context  = is_array($payload) ? $payload : ['message' => (string) $payload];
        $attempts = (int) ($context['attempt'] ?? 0);
        $max      = (int) ($context['maxRetries'] ?? 0);
        $label    = (string) ($context['trxLabel'] ?? '');

        $normalizedLevel = static::normalizeLevel($level, $levels['failure']);
        $status          = strtolower((string) ($context['retryStatus'] ?? ($normalizedLevel === $levels['success'] ? 'success' : 'failure')));
        $statusLabel     = strtoupper($status === 'success' ? 'SUCCESS' : 'FAILED');

        $exceptionClass = (string) ($context['exceptionClass'] ?? 'UnknownException');
        $sqlState       = (string) ($context['sqlState'] ?? '');
        $driverCode     = $context['driverCode'] ?? null;

        $codeParts                             = [];
        $sqlState !== ''       && $codeParts[]       = 'SQLSTATE ' . $sqlState;
        ! is_null($driverCode) && $codeParts[] = 'Driver ' . $driverCode;

        $exceptionSummary = trim($exceptionClass . (count($codeParts) > 0 ? ' (' . implode(', ', $codeParts) . ')' : ''));

        $title = sprintf(
            '[%s] [DATABASE TRANSACTION RETRY - %s] %s After (Attempts: %d/%d) - %s',
            $label,
            $statusLabel,
            $exceptionSummary,
            $attempts,
            $max,
            ucfirst($normalizedLevel)
        );

        $logger->log($normalizedLevel, $title, $context);
    }

    protected static function resolveLogger(string $logFileName): LoggerInterface
    {
        $logging = [];

        if (function_exists('config')) {
            $config = config('database-transaction-retry.logging', []);
            if (is_array($config)) {
                $logging = $config;
            }
        }

        if (! empty($logging['channel']) && $logger = static::resolveChannel($logging['channel'])) {
            return $logger;
        }

        if (! empty($logging['config']) && is_array($logging['config'])) {
            if ($logger = static::resolveBuilder($logging['config'])) {
                return $logger;
            }
        }

        return static::defaultLogger($logFileName);
    }

    protected static function resolveChannel(string $channel): ?LoggerInterface
    {
        try {
            return Log::channel($channel);
        } catch (Throwable) {
            return null;
        }
    }

    protected static function resolveBuilder(array $config): ?LoggerInterface
    {
        try {
            return Log::build($config);
        } catch (Throwable) {
            return null;
        }
    }

    protected static function defaultLogger(string $logFileName): LoggerInterface
    {
        $date = function_exists('now') ? now()->toDateString() : date('Y-m-d');

        $logFilePath = empty($logFileName)
            ? storage_path('logs/' . $date . '/general.log')
            : storage_path('logs/' . $date . "/{$logFileName}.log");

        return Log::build([
            'driver' => 'single',
            'path'   => $logFilePath,
        ]);
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
        $candidate = is_string($level) ? strtolower(trim($level)) : null;

        return $candidate !== '' ? $candidate : $fallback;
    }
}

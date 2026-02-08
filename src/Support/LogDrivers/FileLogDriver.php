<?php

namespace DatabaseTransactions\RetryHelper\Support\LogDrivers;

use DatabaseTransactions\RetryHelper\Contracts\LogDriverInterface;
use DatabaseTransactions\RetryHelper\Enums\LogLevel;
use DatabaseTransactions\RetryHelper\Enums\RetryStatus;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class FileLogDriver implements LogDriverInterface
{
    public function write(array $payload, string $logFileName, string $level): void
    {
        $logger = $this->resolveLogger($logFileName);

        $levels = $this->configuredLevels();

        $context  = is_array($payload) ? $payload : ['message' => (string) $payload];
        $attempts = (int) ($context['attempt'] ?? 0);
        $max      = (int) ($context['maxRetries'] ?? 0);
        $label    = (string) ($context['trxLabel'] ?? '');

        $normalizedLevel = $this->normalizeLevel($level, $levels['failure']);
        $defaultStatus   = $normalizedLevel === $levels['success']
                ? RetryStatus::Success->value
                : RetryStatus::Failure->value;
        $status      = RetryStatus::normalize($context['retryStatus'] ?? null, $defaultStatus);
        $statusLabel = strtoupper(
            $status === RetryStatus::Success->value ? 'SUCCESS' : 'FAILED'
        );

        $exceptionClass = (string) ($context['exceptionClass'] ?? 'UnknownException');
        $sqlState       = (string) ($context['sqlState'] ?? '');
        $driverCode     = $context['driverCode'] ?? null;

        $codeParts                             = [];
        $sqlState !== ''       && $codeParts[] = 'SQLSTATE ' . $sqlState;
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

    protected function resolveLogger(string $logFileName): LoggerInterface
    {
        $logging = [];

        if (function_exists('config')) {
            $config = config('database-transaction-retry.logging', []);
            if (is_array($config)) {
                $logging = $config;
            }
        }

        if (! empty($logging['channel']) && $logger = $this->resolveChannel($logging['channel'])) {
            return $logger;
        }

        if (! empty($logging['config']) && is_array($logging['config'])) {
            if ($logger = $this->resolveBuilder($logging['config'])) {
                return $logger;
            }
        }

        return $this->defaultLogger($logFileName);
    }

    protected function resolveChannel(string $channel): ?LoggerInterface
    {
        try {
            return Log::channel($channel);
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveBuilder(array $config): ?LoggerInterface
    {
        try {
            return Log::build($config);
        } catch (Throwable) {
            return null;
        }
    }

    protected function defaultLogger(string $logFileName): LoggerInterface
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

    protected function configuredLevels(): array
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
            'success' => $this->normalizeLevel($levels['success'] ?? null, $defaults['success']),
            'failure' => $this->normalizeLevel($levels['failure'] ?? null, $defaults['failure']),
        ];
    }

    protected function normalizeLevel(?string $level, string $fallback): string
    {
        $normalizedFallback = LogLevel::normalize($fallback, LogLevel::Error->value);

        return LogLevel::normalize($level, $normalizedFallback);
    }
}

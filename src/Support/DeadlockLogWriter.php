<?php

namespace MysqlDeadlocks\RetryHelper\Support;

use Illuminate\Support\Facades\Log;

class DeadlockLogWriter
{
    public static function write(array $payload, string $logFileName, string $level = 'error'): void
    {
        $date = function_exists('now') ? now()->toDateString() : date('Y-m-d');

        $logFilePath = empty($logFileName)
            ? storage_path('logs/' . $date . '/general.log')
            : storage_path('logs/' . $date . "/{$logFileName}.log");

        $logger = Log::build([
            'driver' => 'single',
            'path'   => $logFilePath,
        ]);

        $context  = is_array($payload) ? $payload : ['message' => (string) $payload];
        $attempts = $context['attempt']    ?? 0;
        $max      = $context['maxRetries'] ?? 0;
        $label    = $context['trxLabel']   ?? '';

        $title = sprintf(
            '[%s] [MYSQL DEADLOCK RETRY - %s] After (Attempts: %d/%d) - %s',
            $label,
            strtoupper($level === 'warning' ? 'SUCCESS' : 'FAILED'),
            $attempts,
            $max,
            ucfirst($level)
        );

        $logger->{$level}($title, $context);
    }
}

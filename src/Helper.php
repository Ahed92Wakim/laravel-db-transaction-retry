<?php

use Illuminate\Support\Facades\Log;

if (!function_exists('getDebugBacktraceArray')) {
    function getDebugBacktraceArray(): array
    {
        $steps = [];
        foreach (debug_backtrace() as $step) {
            $steps[] = [
                'file' => $step['file'] ?? '',
                'class' => $step['class'] ?? '',
                'function' => $step['function'] ?? '',
                'line' => $step['line'] ?? '',
            ];
        }
        return $steps;
    }
}

if (!function_exists('generateLog')) {
    function generateLog($var, $logFileName, $logType = 'error'): void
    {
        $date = function_exists('now') ? now()->toDateString() : date('Y-m-d');

        if (empty($logFileName)) {
            $logFilePath = storage_path('logs/' . $date . '/general.log');
        } else {
            $logFilePath = storage_path("logs/" . $date . "/{$logFileName}.log");
        }
        $log = Log::build([
            'driver' => 'single',
            'path' => $logFilePath,
        ]);
        $payload = is_array($var) ? $var : ['message' => (string)$var];
        $attempts = $var['attempt'] ?? 0;
        $maxRetries = $var['maxRetries'] ?? 0;
        $trxLabel = $var['trxLabel'] ?? '';

        if ($logType === 'warning') {
            // Transaction succeeded after retries
            $title = "[$trxLabel] [MYSQL DEADLOCK RETRY - SUCCESS] After (Attempts: $attempts/$maxRetries) - Warning";
            $log->warning($title, $payload);
        } else {
            // Transaction failed after all attempts
            $title = "[$trxLabel] [MYSQL DEADLOCK RETRY - FAILED] After (Attempts: $attempts/$maxRetries) - Error";
            $log->error($title, $payload);
        }
    }
}
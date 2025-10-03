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
        $errorInfo = $var['errorInfo'][2] ?? '';

        if ($logType === 'error') {
            $log->error($attempts . ' ' . $errorInfo, $payload);
        } elseif ($logType === 'warning') {
            $log->warning($attempts . ' ' . $errorInfo, $payload);
        } else {
            $log->info($attempts . ' ' . $errorInfo, $payload);
        }
    }
}
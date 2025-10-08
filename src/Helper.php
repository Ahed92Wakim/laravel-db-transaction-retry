<?php

use Illuminate\Support\Facades\Log;

if (! function_exists('getDebugBacktraceArray')) {
    function getDebugBacktraceArray(int $option = DEBUG_BACKTRACE_IGNORE_ARGS, int $limit = 15): array
    {
        try {
            return collect(debug_backtrace($option, $limit))
                ->map(fn ($f) => [
                    'file' => $f['file'] ?? null,
                    'line' => $f['line'] ?? null,
                    'function' => $f['function'] ?? null,
                    'class' => $f['class'] ?? null,
                    'type' => $f['type'] ?? null,
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }
}

if (! function_exists('stringifyBindings')) {
    function stringifyBindings(array $bindings): array
    {
        return array_map(function ($b) {
            if ($b instanceof \DateTimeInterface) {
                return $b->format('Y-m-d H:i:s.u');
            }
            if (is_object($b)) {
                return '[object '.get_class($b).']';
            }
            if (is_resource($b)) {
                return '[resource]';
            }
            if (is_string($b)) {
                // Trim very long strings to avoid log bloat
                return mb_strlen($b) > 500 ? (mb_substr($b, 0, 500).'…[+trimmed]') : $b;
            }
            if (is_array($b)) {
                // Compact arrays
                $json = @json_encode($b, JSON_UNESCAPED_UNICODE);

                return $json !== false
                    ? (mb_strlen($json) > 500 ? (mb_substr($json, 0, 500).'…[+trimmed]') : $json)
                    : '[array]';
            }

            return $b;
        }, $bindings);
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
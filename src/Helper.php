<?php


use Illuminate\Support\Facades\Log;

if (! function_exists('getDebugBacktraceArray')) {
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

if (! function_exists('generateLog')) {
    function generateLog($var, $logFileName): void
    {
        if (is_null($logFileName)) {
            $logFilePath = storage_path('logs/general_'.now()->toDateString().'.log');
        } else {
            $logFilePath = storage_path("logs/{$logFileName}_".now()->toDateString().'.log');
        }
        Log::build([
            'driver' => 'single',
            'path' => $logFilePath,
        ])->error(var_export($var, true));
    }
}
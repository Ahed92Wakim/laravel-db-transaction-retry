<?php

namespace MysqlDeadlocks\RetryHelper\Support;

use Throwable;

class TraceFormatter
{
    public static function snapshot(
        int $option = DEBUG_BACKTRACE_IGNORE_ARGS,
        int $limit = 15
    ): array {
        try {
            return collect(debug_backtrace($option, $limit))
                ->map(static fn (array $frame) => [
                    'file'     => $frame['file']     ?? null,
                    'line'     => $frame['line']     ?? null,
                    'function' => $frame['function'] ?? null,
                    'class'    => $frame['class']    ?? null,
                    'type'     => $frame['type']     ?? null,
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }
}

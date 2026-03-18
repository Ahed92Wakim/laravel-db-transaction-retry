<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DateTimeImmutable;

class TimeHelper
{
    /**
     * Calculate elapsed milliseconds from a given start payload (hrtime or microtime).
     */
    public static function calculateElapsedMs(array $context): int
    {
        $startHrtime = $context['start_hrtime'] ?? null;
        if (! is_null($startHrtime) && function_exists('hrtime')) {
            $elapsedNs = hrtime(true) - (int) $startHrtime;

            return (int) round($elapsedNs / 1_000_000);
        }

        $startMicro = (float) ($context['start_microtime'] ?? microtime(true));

        return (int) round((microtime(true) - $startMicro) * 1000);
    }

    /**
     * Get the current timestamp using Laravel's now() if available, or fallback to DateTimeImmutable.
     */
    public static function nowTimestamp(): string
    {
        if (function_exists('now')) {
            return now()->format('Y-m-d H:i:s.v');
        }

        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
    }

    /**
     * Get timestamp from a specific microtime float.
     */
    public static function timestampFromMicrotime(float $microtime): string
    {
        $formatted = sprintf('%.6F', $microtime);
        $date      = DateTimeImmutable::createFromFormat('U.u', $formatted);

        if ($date === false) {
            return self::nowTimestamp();
        }

        return $date->format('Y-m-d H:i:s.v');
    }
}

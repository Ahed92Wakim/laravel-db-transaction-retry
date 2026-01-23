<?php

namespace DatabaseTransactions\RetryHelper\Enums;

enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert     = 'alert';
    case Critical  = 'critical';
    case Error     = 'error';
    case Warning   = 'warning';
    case Notice    = 'notice';
    case Info      = 'info';
    case Debug     = 'debug';

    public static function values(): array
    {
        return array_map(static fn (self $level) => $level->value, self::cases());
    }

    public static function normalize(?string $value, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized)?->value ?? $fallback;
    }
}

<?php

namespace DatabaseTransactions\RetryHelper\Enums;

enum RetryStatus: string
{
    case Attempt = 'attempt';
    case Failure = 'failure';
    case Success = 'success';

    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }

    public static function normalize(?string $value, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized)?->value ?? $fallback;
    }
}

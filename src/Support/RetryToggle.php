<?php

namespace DatabaseTransactions\RetryHelper\Support;

class RetryToggle
{
    protected const MARKER_FILENAME = 'retry-disabled.marker';

    protected static ?bool $override = null;

    public static function isEnabled(array $config = []): bool
    {
        if (file_exists(static::markerPath())) {
            return false;
        }

        if (is_bool(static::$override)) {
            return static::$override;
        }

        $fallback = static::normalizeBoolean($config['enabled'] ?? null, true);

        return $fallback;
    }

    public static function enable(): bool
    {
        static::$override = null;

        return static::removeMarker();
    }

    public static function disable(): bool
    {
        static::$override = false;

        return static::writeMarker();
    }

    public static function markerPath(): string
    {
        return static::stateDirectory() . '/' . static::MARKER_FILENAME;
    }

    public static function isExplicitlyDisabledValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return static::normalizeBoolean($value, true) === false;
    }

    protected static function writeMarker(): bool
    {
        $directory = static::stateDirectory();

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return false;
        }

        return file_put_contents(static::markerPath(), (string) time()) !== false;
    }

    protected static function removeMarker(): bool
    {
        $path = static::markerPath();

        if (file_exists($path) && ! @unlink($path)) {
            return false;
        }

        static::cleanupStateDirectory();

        return true;
    }

    protected static function stateDirectory(): string
    {
        if (function_exists('config')) {
            $configured = config('database-transaction-retry.state_path');

            if (is_string($configured)) {
                $configured = trim($configured);

                if ($configured !== '') {
                    return rtrim($configured, '/');
                }
            }
        }

        if (function_exists('storage_path')) {
            return rtrim(storage_path('database-transaction-retry/runtime'), '/');
        }

        return dirname(__DIR__, 2) . '/storage/runtime';
    }

    protected static function normalizeBoolean(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            if ($value === '') {
                return $fallback;
            }

            if (in_array($value, ['false', '0', 'off', 'no'], true)) {
                return false;
            }

            if (in_array($value, ['true', '1', 'on', 'yes'], true)) {
                return true;
            }
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return $fallback;
    }

    protected static function cleanupStateDirectory(): void
    {
        $directory = static::stateDirectory();

        if (is_dir($directory)) {
            $entries = @scandir($directory) ?: [];
            $entries = array_diff($entries, ['.', '..']);

            if (count($entries) === 0) {
                @rmdir($directory);
            }
        }

        $root = dirname($directory);

        if (is_dir($root)) {
            $entries = @scandir($root) ?: [];
            $entries = array_diff($entries, ['.', '..']);

            if (count($entries) === 0) {
                @rmdir($root);
            }
        }
    }
}

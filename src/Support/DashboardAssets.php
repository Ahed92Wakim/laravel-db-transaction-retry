<?php

namespace DatabaseTransactions\RetryHelper\Support;

final class DashboardAssets
{
    public const VENDOR_PATH = 'vendor/laravel-db-transaction-retry';

    public static function dashboardPath(): string
    {
        $path = function_exists('config')
            ? (string) config('database-transaction-retry.dashboard.path', 'transaction-retry')
            : 'transaction-retry';

        $path = trim($path, '/');

        return $path === '' ? 'transaction-retry' : $path;
    }

    public static function publicRelativePath(): string
    {
        return trim(self::VENDOR_PATH . '/' . self::dashboardPath(), '/');
    }

    public static function publicPath(): string
    {
        $relativePath = self::publicRelativePath();

        return function_exists('public_path')
            ? public_path($relativePath)
            : base_path('public/' . $relativePath);
    }

    public static function indexPath(): string
    {
        return self::publicPath() . '/index.html';
    }

    public static function assetPath(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return self::indexPath();
        }

        return rtrim(self::publicPath(), '/') . '/' . $path;
    }

    public static function contentTypeFor(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'application/javascript; charset=UTF-8',
            'json', 'map' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'ico'   => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
            'wasm'  => 'application/wasm',
            'txt'   => 'text/plain; charset=UTF-8',
            'xml'   => 'application/xml; charset=UTF-8',
            'html'  => 'text/html; charset=UTF-8',
            default => null,
        };
    }
}

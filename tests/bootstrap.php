<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $base = sys_get_temp_dir() . '/laravel-mysql-deadlock-retry';

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

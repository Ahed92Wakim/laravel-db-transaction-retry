<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $base = sys_get_temp_dir() . '/laravel-db-transaction-retry';

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $app = \Illuminate\Container\Container::getInstance();

        if ($app && method_exists($app, 'basePath')) {
            return $app->basePath($path);
        }

        $base = sys_get_temp_dir() . '/laravel-db-transaction-retry';

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        $app = \Illuminate\Container\Container::getInstance();

        if ($app && method_exists($app, 'path')) {
            return $app->path($path);
        }

        return base_path('app' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        $app = \Illuminate\Container\Container::getInstance();

        if ($app && method_exists($app, 'configPath')) {
            return $app->configPath($path);
        }

        return base_path('config' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (! function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        $app = \Illuminate\Container\Container::getInstance();

        if ($app && method_exists($app, 'databasePath')) {
            return $app->databasePath($path);
        }

        return base_path('database' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (! function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        $app = \Illuminate\Container\Container::getInstance();

        if ($app && method_exists($app, 'publicPath')) {
            return $app->publicPath($path);
        }

        return base_path('public' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

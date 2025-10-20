<?php

namespace MysqlDeadlocks\RetryHelper\Providers;

use Illuminate\Support\ServiceProvider;

class DatabaseRetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/mysql-deadlock-retry.php',
            'mysql-deadlock-retry'
        );
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $configPath = function_exists('config_path')
                ? config_path('mysql-deadlock-retry.php')
                : $this->app->basePath('config/mysql-deadlock-retry.php');

            $this->publishes([
                __DIR__ . '/../../config/mysql-deadlock-retry.php' => $configPath,
            ], 'mysql-deadlock-retry-config');
        }
    }
}

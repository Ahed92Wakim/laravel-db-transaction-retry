<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Console\InstallCommand;
use DatabaseTransactions\RetryHelper\Console\RollPartitionsCommand;
use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class DatabaseTransactionRetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/database-transaction-retry.php',
            'database-transaction-retry'
        );
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $configPath = function_exists('config_path')
                ? config_path('database-transaction-retry.php')
                : $this->app->basePath('config/database-transaction-retry.php');

            $this->publishes([
                __DIR__ . '/../../config/database-transaction-retry.php' => $configPath,
            ], 'database-transaction-retry-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations/2025_01_17_000000_create_transaction_retry_events_table.php' => $this->app->databasePath(
                    'migrations/2025_01_17_000000_create_transaction_retry_events_table.php'
                ),
            ], 'database-transaction-retry-migrations');

            $this->commands([
                InstallCommand::class,
                RollPartitionsCommand::class,
                StartRetryCommand::class,
                StopRetryCommand::class,
            ]);
        }
    }
}

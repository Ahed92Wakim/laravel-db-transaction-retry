<?php

namespace DatabaseTransactions\RetryHelper\Providers;

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
                __DIR__ . '/../../database/migrations/create_transaction_retry_events.php' => $this->app->databasePath(
                    'migrations/' . date('Y_m_d_His') . '_create_transaction_retry_events.php'
                ),
            ], 'database-transaction-retry-migrations');

            $this->commands([
                RollPartitionsCommand::class,
                StartRetryCommand::class,
                StopRetryCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule
                    ->command('db-transaction-retry:roll-partitions --hours=24 --table=transaction_retry_events')
                    ->hourly();
            });
        }
    }
}

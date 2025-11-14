<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use Illuminate\Support\ServiceProvider;

class DatabaseTransactionRetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/database-transaction-retry.php',
            'database-transaction-retry'
        );

        $this->app->register(DbMacroServiceProvider::class);
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

            $this->commands([
                StartRetryCommand::class,
                StopRetryCommand::class,
            ]);
        }
    }
}

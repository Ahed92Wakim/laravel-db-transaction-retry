<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Console\InstallCommand;
use DatabaseTransactions\RetryHelper\Console\RollPartitionsCommand;
use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use DatabaseTransactions\RetryHelper\Support\DashboardAssets;
use DatabaseTransactions\RetryHelper\Support\QueryExceptionLogger;
use DatabaseTransactions\RetryHelper\Support\UninstallAction;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;

class DatabaseTransactionRetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/database-transaction-retry.php',
            'database-transaction-retry'
        );

        if (method_exists($this->app, 'register')) {
            $this->app->register(DbMacroServiceProvider::class);
            $this->app->register(EventServiceProvider::class);
        }
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerQueryExceptionLogger();

        if ($this->app->runningInConsole()) {
            $this->registerComposerEvents();
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    protected function registerRoutes(): void
    {
        if (method_exists($this->app, 'routesAreCached') && $this->app->routesAreCached()) {
            return;
        }

        $dashboardEnabled = (bool) config('database-transaction-retry.dashboard.enabled', true);
        $apiEnabled       = (bool) config('database-transaction-retry.api.enabled', true);

        if ($dashboardEnabled || $apiEnabled) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        }
    }

    /**
     * @throws BindingResolutionException
     */
    protected function registerQueryExceptionLogger(): void
    {
        if (! $this->app->bound(ExceptionHandler::class)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (QueryException $exception): void {
            QueryExceptionLogger::report($exception);
        });
    }

    protected function registerPublishing(): void
    {
        $configPath = function_exists('config_path')
            ? config_path('database-transaction-retry.php')
            : $this->app->basePath('config/database-transaction-retry.php');

        $this->publishes([
            __DIR__ . '/../../config/database-transaction-retry.php' => $configPath,
        ], 'database-transaction-retry-config');

        $this->publishes([
            __DIR__ . '/../../database/migrations/0001_01_01_000000_create_transaction_retry_events_table.php'
            => $this->app->databasePath('migrations/0001_01_01_000000_create_transaction_retry_events_table.php'),
            __DIR__ . '/../../database/migrations/0001_01_01_000001_create_db_transaction_logs_tables.php'
            => $this->app->databasePath('migrations/0001_01_01_000001_create_db_transaction_logs_tables.php'),
            __DIR__ . '/../../database/migrations/0001_01_01_000002_create_db_exceptions_table.php'
            => $this->app->databasePath('migrations/0001_01_01_000002_create_db_exceptions_table.php'),
            __DIR__ . '/../../database/migrations/0001_01_01_000003_create_db_request_logs_table.php'
            => $this->app->databasePath('migrations/0001_01_01_000003_create_db_request_logs_table.php'),
        ], 'database-transaction-retry-migrations');

        $providerPath = function_exists('app_path')
            ? app_path('Providers/TransactionRetryDashboardServiceProvider.php')
            : $this->app->basePath('app/Providers/TransactionRetryDashboardServiceProvider.php');

        $this->publishes([
            __DIR__ . '/../../stubs/TransactionRetryDashboardServiceProvider.stub' => $providerPath,
        ], 'database-transaction-retry-dashboard-provider');

        $publicPath = DashboardAssets::publicPath();

        $this->publishes([
            __DIR__ . '/../../dashboard/out' => $publicPath,
        ], 'database-transaction-retry-dashboard');
    }

    protected function registerCommands(): void
    {
        $this->commands([
            InstallCommand::class,
            RollPartitionsCommand::class,
            StartRetryCommand::class,
            StopRetryCommand::class,
        ]);
    }

    protected function registerComposerEvents(): void
    {
        if (! $this->app->bound('events')) {
            return;
        }

        $this->app['events']->listen('composer_package.ahed92wakim/laravel-db-transaction-retry:pre_uninstall', function (): void {
            $this->app->make(UninstallAction::class)->handle();
        });
    }

}

<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Console\InstallCommand;
use DatabaseTransactions\RetryHelper\Console\RollPartitionsCommand;
use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use DatabaseTransactions\RetryHelper\Support\DashboardAssets;
use DatabaseTransactions\RetryHelper\Support\QueryExceptionLogger;
use DatabaseTransactions\RetryHelper\Support\SlowTransactionMonitor;
use DatabaseTransactions\RetryHelper\Support\UninstallAction;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Events\RequestHandled;
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
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerSlowTransactionMonitor();
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

    protected function registerSlowTransactionMonitor(): void
    {
        if (! function_exists('config')) {
            return;
        }

        $config = config('database-transaction-retry.slow_transactions', []);
        if (! is_array($config) || ! ($config['enabled'] ?? true)) {
            return;
        }

        if (! $this->app->bound('events')) {
            return;
        }

        $this->app->singleton(SlowTransactionMonitor::class, static function () use ($config): SlowTransactionMonitor {
            return new SlowTransactionMonitor($config);
        });

        $events = $this->app['events'];

        $events->listen(TransactionBeginning::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleTransactionBeginning($event);
        });

        $events->listen(TransactionCommitted::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleTransactionCommitted($event);
        });

        $events->listen(TransactionRolledBack::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleTransactionRolledBack($event);
        });

        $events->listen(QueryExecuted::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleQueryExecuted($event);
        });

        if (class_exists(RequestHandled::class)) {
            $events->listen(RequestHandled::class, function ($event): void {
                $this->app->make(SlowTransactionMonitor::class)->handleRequestHandled($event);
            });
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
            __DIR__ . '/../../database/migrations/2025_01_17_000000_create_transaction_retry_events_table.php'
            => $this->app->databasePath('migrations/2025_01_17_000000_create_transaction_retry_events_table.php'),
            __DIR__ . '/../../database/migrations/2025_01_17_000001_create_db_transaction_logs_tables.php'
            => $this->app->databasePath('migrations/2025_01_17_000001_create_db_transaction_logs_tables.php'),
            __DIR__ . '/../../database/migrations/2025_01_17_000002_create_db_exceptions_table.php'
            => $this->app->databasePath('migrations/2025_01_17_000002_create_db_exceptions_table.php'),
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

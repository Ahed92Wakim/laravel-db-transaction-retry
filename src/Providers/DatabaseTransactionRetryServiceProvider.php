<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Console\InstallCommand;
use DatabaseTransactions\RetryHelper\Console\RollPartitionsCommand;
use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use DatabaseTransactions\RetryHelper\Support\SlowTransactionMonitor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
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

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerSlowTransactionMonitor();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
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
        ], 'database-transaction-retry-migrations');

        $providerPath = function_exists('app_path')
            ? app_path('Providers/TransactionRetryDashboardServiceProvider.php')
            : $this->app->basePath('app/Providers/TransactionRetryDashboardServiceProvider.php');

        $this->publishes([
            __DIR__ . '/../../stubs/TransactionRetryDashboardServiceProvider.stub' => $providerPath,
        ], 'database-transaction-retry-dashboard-provider');

        $dashboardPath = trim((string) config('database-transaction-retry.dashboard.path', 'transaction-retry'), '/');
        $dashboardPath = $dashboardPath === '' ? 'transaction-retry' : $dashboardPath;
        $publicPath    = function_exists('public_path')
            ? public_path($dashboardPath)
            : $this->app->basePath('public/' . $dashboardPath);

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

}

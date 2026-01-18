<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Console\InstallCommand;
use DatabaseTransactions\RetryHelper\Console\RollPartitionsCommand;
use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
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
        $this->registerDashboardGate();
        $this->registerRoutes();

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

            $dashboardPath = trim((string) config('database-transaction-retry.dashboard.path', 'transaction-retry'), '/');
            $dashboardPath = $dashboardPath === '' ? 'transaction-retry' : $dashboardPath;
            $publicPath    = function_exists('public_path')
                ? public_path($dashboardPath)
                : $this->app->basePath('public/' . $dashboardPath);

            $this->publishes([
                __DIR__ . '/../../dashboard/out' => $publicPath,
            ], 'database-transaction-retry-dashboard');

            $this->commands([
                InstallCommand::class,
                RollPartitionsCommand::class,
                StartRetryCommand::class,
                StopRetryCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        if ((bool) config('database-transaction-retry.dashboard.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        }

        if ((bool) config('database-transaction-retry.api.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        }
    }

    protected function registerDashboardGate(): void
    {
        if (! class_exists(Gate::class)) {
            return;
        }

        $gate = (string) config('database-transaction-retry.dashboard.gate', 'viewTransactionRetryDashboard');
        if ($gate === '') {
            return;
        }

        Gate::define($gate, function ($user = null): bool {
            $allowed = config('database-transaction-retry.dashboard.allowed_emails', []);
            if (is_array($allowed) && ! empty($allowed)) {
                $email = is_object($user) && property_exists($user, 'email') ? $user->email : null;

                return is_string($email) && in_array($email, $allowed, true);
            }

            return app()->environment('local');
        });
    }
}

<?php

namespace DatabaseTransactions\RetryHelper\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

readonly class UninstallAction
{
    public function __construct(private Filesystem $files)
    {
    }

    public function handle(): void
    {
        $this->removeDashboardProviderRegistration();

        $files = array_filter([
            $this->configPath(),
            $this->dashboardProviderPath(),
            ...$this->migrationPaths(),
        ]);

        if ($files !== []) {
            $this->files->delete($files);
        }

        $dashboardPath = $this->dashboardAssetsPath();

        if ($dashboardPath !== null) {
            $this->files->deleteDirectory($dashboardPath);
        }
    }

    private function removeDashboardProviderRegistration(): void
    {
        if (! method_exists(ServiceProvider::class, 'removeProviderFromBootstrapFile')) {
            return;
        }

        $bootstrapPath = function_exists('base_path')
            ? base_path('bootstrap/providers.php')
            : null;

        ServiceProvider::removeProviderFromBootstrapFile(
            'TransactionRetryDashboardServiceProvider',
            $bootstrapPath
        );
    }

    private function configPath(): string
    {
        return function_exists('config_path')
            ? config_path('database-transaction-retry.php')
            : base_path('config/database-transaction-retry.php');
    }

    private function dashboardProviderPath(): string
    {
        return function_exists('app_path')
            ? app_path('Providers/TransactionRetryDashboardServiceProvider.php')
            : base_path('app/Providers/TransactionRetryDashboardServiceProvider.php');
    }

    /**
     * @return array<int, string>
     */
    private function migrationPaths(): array
    {
        $migrationsPath = function_exists('database_path')
            ? database_path('migrations')
            : base_path('database/migrations');

        return [
            $migrationsPath . '/2025_01_17_000000_create_transaction_retry_events_table.php',
            $migrationsPath . '/2025_01_17_000001_create_db_transaction_logs_tables.php',
            $migrationsPath . '/2025_01_17_000002_create_db_exceptions_table.php',
        ];
    }

    private function dashboardAssetsPath(): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        return DashboardAssets::publicPath();
    }
}

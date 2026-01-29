<?php

use DatabaseTransactions\RetryHelper\Support\UninstallAction;
use Illuminate\Filesystem\Filesystem;

test('uninstall action removes published assets', function (): void {
    $basePath = sys_get_temp_dir() . '/laravel-db-transaction-retry/uninstall-' . uniqid('', true);

    $this->app->setBasePath($basePath);
    $this->app['config']->set('database-transaction-retry.dashboard.path', 'transaction-retry');

    $files = new Filesystem();
    $files->ensureDirectoryExists(config_path());
    $files->ensureDirectoryExists(app_path('Providers'));
    $files->ensureDirectoryExists(database_path('migrations'));
    $files->ensureDirectoryExists(base_path('bootstrap'));
    $files->ensureDirectoryExists(public_path('transaction-retry'));

    file_put_contents(config_path('database-transaction-retry.php'), 'config');
    file_put_contents(app_path('Providers/TransactionRetryDashboardServiceProvider.php'), 'provider');
    file_put_contents(database_path('migrations/2025_01_17_000000_create_transaction_retry_events_table.php'), 'migration');
    file_put_contents(database_path('migrations/2025_01_17_000001_create_db_transaction_logs_tables.php'), 'migration');
    file_put_contents(database_path('migrations/2025_01_17_000002_create_db_exceptions_table.php'), 'migration');
    file_put_contents(base_path('bootstrap/providers.php'), <<<'PHP'
<?php

return [
    App\Providers\TransactionRetryDashboardServiceProvider::class,
    App\Providers\AnotherProvider::class,
];
PHP
    );
    file_put_contents(public_path('transaction-retry/index.html'), '<html></html>');

    (new UninstallAction($files))->handle();

    expect(file_exists(config_path('database-transaction-retry.php')))->toBeFalse();
    expect(file_exists(app_path('Providers/TransactionRetryDashboardServiceProvider.php')))->toBeFalse();
    expect(file_exists(database_path('migrations/2025_01_17_000000_create_transaction_retry_events_table.php')))->toBeFalse();
    expect(file_exists(database_path('migrations/2025_01_17_000001_create_db_transaction_logs_tables.php')))->toBeFalse();
    expect(file_exists(database_path('migrations/2025_01_17_000002_create_db_exceptions_table.php')))->toBeFalse();
    expect(is_dir(public_path('transaction-retry')))->toBeFalse();
    expect(file_get_contents(base_path('bootstrap/providers.php')))->not->toContain('TransactionRetryDashboardServiceProvider');
});

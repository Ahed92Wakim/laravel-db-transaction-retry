<?php

namespace DatabaseTransactions\RetryHelper\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'db-transaction-retry:install {--force : Overwrite any existing files}';

    protected $description = 'Publish the database transaction retry config and migration.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', [
            '--tag' => 'database-transaction-retry-config',
            '--force' => $force,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'database-transaction-retry-migrations',
            '--force' => $force,
        ]);

        $this->info('Database transaction retry assets published.');
        $this->line('Run `php artisan migrate` to create the transaction_retry_events table.');

        return self::SUCCESS;
    }
}

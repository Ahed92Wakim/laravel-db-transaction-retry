<?php

namespace DatabaseTransactions\RetryHelper\Console;

use DatabaseTransactions\RetryHelper\Support\RetryToggle;
use Illuminate\Console\Command;

class StopRetryCommand extends Command
{
    protected $signature = 'db-transaction-retry:stop';

    protected $description = 'Disable database transaction retry handling.';

    public function handle(): int
    {
        $configEnabled      = config('database-transaction-retry.enabled');
        $explicitlyDisabled = RetryToggle::isExplicitlyDisabledValue($configEnabled);
        if ($explicitlyDisabled) {
            $this->warn('Base configuration already disables retries via `database-transaction-retry.enabled` or `DB_TRANSACTION_RETRY_ENABLED`.');

            return self::SUCCESS;
        }
        config(['database-transaction-retry.enabled' => false]);

        $persisted = RetryToggle::disable();
        $marker    = RetryToggle::markerPath();

        if ($persisted) {
            $this->info('Database transaction retries have been disabled.');
            $this->line("Created toggle marker: {$marker}");
        } else {
            $this->warn('Database transaction retries disabled for this process, but the toggle marker could not be written.');
            $this->line("Please create {$marker} manually or adjust permissions.");
        }

        $state = RetryToggle::isEnabled(config('database-transaction-retry', []))
            ? 'ENABLED'
            : 'DISABLED';

        if ($persisted) {
            $this->line("Current status: {$state}");
        } else {
            $this->line("Current status: {$state} (runtime only; persistence failed)");
            $this->warn('Retries will be re-enabled on the next run unless the marker is created manually.');
        }

        return self::SUCCESS;
    }
}

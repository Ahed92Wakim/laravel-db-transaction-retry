<?php

namespace DatabaseTransactions\RetryHelper\Console;

use DatabaseTransactions\RetryHelper\Support\RetryToggle;
use Illuminate\Console\Command;

class StartRetryCommand extends Command
{
    protected $signature = 'db-transaction-retry:start';

    protected $description = 'Enable database transaction retry handling.';

    public function handle(): int
    {
        $configEnabled      = config('database-transaction-retry.enabled');
        $explicitlyDisabled = RetryToggle::isExplicitlyDisabledValue($configEnabled);
        if ($explicitlyDisabled) {
            $this->warn('Base configuration keeps retries disabled. Update `database-transaction-retry.enabled` (or `DB_TRANSACTION_RETRY_ENABLED`) if you want retries to stay on after this run.');

            return self::SUCCESS;
        }
        config(['database-transaction-retry.enabled' => true]);

        $persisted = RetryToggle::enable();
        $marker    = RetryToggle::markerPath();

        if ($persisted) {
            $this->info('Database transaction retries have been enabled.');
            $this->line("Cleared toggle marker: {$marker}");
        } else {
            $this->warn('Database transaction retries could not be fully enabled because the toggle marker could not be removed.');
            $this->line("Please delete {$marker} manually or adjust permissions.");
        }

        $state = RetryToggle::isEnabled(config('database-transaction-retry', []))
            ? 'ENABLED'
            : 'DISABLED';

        if ($persisted) {
            $this->line("Current status: {$state}");
        } else {
            $this->line("Current status: {$state} (marker still present; retries remain disabled)");
        }

        return self::SUCCESS;
    }
}

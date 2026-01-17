<?php

namespace DatabaseTransactions\RetryHelper\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RollPartitionsCommand extends Command
{
    protected $signature = 'db-transaction-retry:roll-partitions {--hours=24 : Hours ahead to ensure partitions exist} {--table=transaction_retry_events : Table name to partition}';

    protected $description = 'Create hourly MySQL partitions ahead of current time for transaction retry events.';

    public function handle(): int
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();

        if ($driver !== 'mysql') {
            $this->warn('Partition rolling is only supported for MySQL connections.');

            return self::SUCCESS;
        }

        $table = trim((string)$this->option('table'));
        if ($table === '') {
            $this->error('Table option cannot be empty.');

            return self::FAILURE;
        }

        if (!Schema::connection($connection->getName())->hasTable($table)) {
            $this->error("Table not found: {$table}");

            return self::FAILURE;
        }

        $hours = (int)$this->option('hours');
        if ($hours < 1) {
            $this->error('Hours option must be 1 or greater.');

            return self::FAILURE;
        }

        $database   = $connection->getDatabaseName();
        $partitions = DB::select(
            'SELECT PARTITION_NAME, PARTITION_DESCRIPTION, FROM_UNIXTIME(PARTITION_DESCRIPTION, "%Y-%m-%d %H:%i:%s") as candidate FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );

        $maxBoundary = null;
        $hasMax      = false;

        foreach ($partitions as $partition) {
            $name        = $partition->PARTITION_NAME        ?? null;
            $description = $partition->PARTITION_DESCRIPTION ?? null;
            $candidate   = $partition->candidate             ?? null;

            if (is_null($name) || is_null($description)) {
                continue;
            }

            if (strtoupper((string)$description) === 'MAXVALUE') {
                $hasMax = true;
                continue;
            }

            $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$candidate);
            if ($candidate === false) {
                continue;
            }

            if (is_null($maxBoundary) || $candidate > $maxBoundary) {
                $maxBoundary = $candidate;
            }
        }

        if (!$hasMax) {
            $this->error('Partition p_max not found. Ensure the table was created with a MAXVALUE partition.');

            return self::FAILURE;
        }

        if (is_null($maxBoundary)) {
            $this->error('Unable to determine the latest partition boundary.');

            return self::FAILURE;
        }

        $now    = new DateTimeImmutable('now');
        $target = $now->setTime((int)$now->format('H'), 0, 0)->modify('+' . $hours . ' hours');

        $boundaries = [];
        $boundary   = $maxBoundary;

        while ($boundary < $target) {
            $boundary     = $boundary->modify('+1 hour');
            $boundaries[] = $boundary;
        }

        if (count($boundaries) === 0) {
            $this->info('No new partitions needed.');

            return self::SUCCESS;
        }

        $definitions = [];
        foreach ($boundaries as $item) {
            $definitions[] = sprintf(
                "PARTITION p_%s VALUES LESS THAN (UNIX_TIMESTAMP('%s'))",
                $item->format('YmdH'),
                $item->format('Y-m-d H:i:s')
            );
        }

        $definitions[] = 'PARTITION p_max VALUES LESS THAN (MAXVALUE)';

        $statement = sprintf(
            'ALTER TABLE `%s` REORGANIZE PARTITION p_max INTO (%s)',
            $table,
            implode(', ', $definitions)
        );

        DB::statement($statement);

        $this->info('Partitions rolled successfully.');

        return self::SUCCESS;
    }
}

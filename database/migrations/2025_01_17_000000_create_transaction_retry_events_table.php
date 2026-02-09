<?php

use DatabaseTransactions\RetryHelper\Enums\LogLevel;
use DatabaseTransactions\RetryHelper\Enums\RetryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $tableName = $this->resolveTableName();
        $isMysql   = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create($tableName, function (Blueprint $table) use ($isMysql): void {
            if ($isMysql) {
                $table->unsignedBigInteger('id', true);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            } else {
                $table->bigIncrements('id');
                $table->timestamps();
            }

            $table->timestamp('occurred_at')->nullable()->index();
            $table->enum('retry_status', RetryStatus::values())->nullable()->index();
            $table->enum('log_level', LogLevel::values())->nullable()->index();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(0);
            $table->string('trx_label', 120)->nullable()->index();
            $table->string('retry_group_id', 64)->index();
            $table->string('exception_class', 255)->nullable()->index();
            $table->string('sql_state', 10)->nullable()->index();
            $table->unsignedInteger('driver_code')->nullable()->index();
            $table->string('connection', 100)->nullable();
            $table->longText('raw_sql')->nullable();
            $table->json('error_info')->nullable();
            $table->string('method', 10)->nullable()->index();
            $table->string('route_name', 255)->nullable()->index();
            $table->text('url')->nullable();
            $table->string('user_type', 255)->nullable()->index();
            $table->string('user_id', 64)->nullable()->index();
            $table->unsignedSmallInteger('auth_header_len')->nullable();
            $table->string('route_hash', 64)->nullable()->index();
            $table->string('query_hash', 64)->nullable()->index();
            $table->string('event_hash', 64)->nullable()->index();
            $table->json('context')->nullable();

            if ($isMysql) {
                $table->primary(['id', 'created_at']);
                $table->index('created_at');
            }
        });

        if ($isMysql) {
            $this->addHourlyPartitions($tableName);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->resolveTableName());
    }

    private function addHourlyPartitions(string $tableName): void
    {
        $now   = new DateTimeImmutable('now');
        $start = $now->setTime((int) $now->format('H'), 0, 0);

        $partitions   = [];
        $partitions[] = sprintf(
            "PARTITION p_history VALUES LESS THAN (UNIX_TIMESTAMP('%s'))",
            $start->format('Y-m-d H:i:s')
        );

        $hoursAhead = 24;
        for ($i = 0; $i < $hoursAhead; $i++) {
            $boundary     = $start->modify('+' . ($i + 1) . ' hours');
            $partitions[] = sprintf(
                "PARTITION p_%s VALUES LESS THAN (UNIX_TIMESTAMP('%s'))",
                $boundary->format('YmdH'),
                $boundary->format('Y-m-d H:i:s')
            );
        }

        $partitions[] = 'PARTITION p_max VALUES LESS THAN (MAXVALUE)';

        $sql = sprintf(
            'ALTER TABLE `%s` PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (%s)',
            $tableName,
            implode(', ', $partitions)
        );

        DB::statement($sql);
    }

    private function resolveTableName(): string
    {
        $default = 'transaction_retry_events';

        if (! function_exists('config')) {
            return $default;
        }

        $table = config('database-transaction-retry.logging.table', $default);

        if (! is_string($table)) {
            return $default;
        }

        $table = trim($table);

        return $table === '' ? $default : $table;
    }
};

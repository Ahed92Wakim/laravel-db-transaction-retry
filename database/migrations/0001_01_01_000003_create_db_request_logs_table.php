<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $logTable = $this->resolveTableName('database-transaction-retry.request_logging.log_table', 'db_request_logs');
        $isMysql  = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create($logTable, function (Blueprint $table) use ($isMysql): void {
            if ($isMysql) {
                $table->unsignedBigInteger('id', true);
            } else {
                $table->bigIncrements('id');
            }

            $table->timestamp('started_at', 3);
            $table->timestamp('completed_at', 3);
            $table->unsignedInteger('elapsed_ms');
            $table->unsignedInteger('total_queries_count');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('route_name', 255)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable()->index();

            $table->index('started_at', 'idx_started_at');
            $table->index('completed_at', 'idx_completed_at');
            $table->index('user_id', 'idx_user_id');
            $table->index('route_name', 'idx_route_name');
            $table->index('http_method', 'idx_http_method');

            if ($isMysql) {
                $table->primary(['id', 'completed_at']);
                $table->index('completed_at');
            }
        });

        if ($isMysql) {
            $this->addHourlyPartitions($logTable);
        }
    }

    public function down(): void
    {
        $logTable = $this->resolveTableName('database-transaction-retry.request_logging.log_table', 'db_request_logs');

        Schema::dropIfExists($logTable);
    }

    private function resolveTableName(string $key, string $default): string
    {
        if (! function_exists('config')) {
            return $default;
        }

        $table = config($key, $default);

        if (! is_string($table)) {
            return $default;
        }

        $table = trim($table);

        return $table === '' ? $default : $table;
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
            'ALTER TABLE `%s` PARTITION BY RANGE (FLOOR(UNIX_TIMESTAMP(completed_at))) (%s)',
            $tableName,
            implode(', ', $partitions)
        );

        DB::statement($sql);
    }
};

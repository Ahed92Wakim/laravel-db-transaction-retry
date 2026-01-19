<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $logTable   = $this->resolveTableName('database-transaction-retry.slow_transactions.log_table', 'db_transaction_logs');
        $queryTable = $this->resolveTableName('database-transaction-retry.slow_transactions.query_table', 'db_transaction_queries');
        $isMysql    = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create($logTable, function (Blueprint $table) use ($isMysql): void {
            if ($isMysql) {
                $table->unsignedBigInteger('id', true);
            } else {
                $table->bigIncrements('id');
            }

            $table->string('transaction_label', 255)->nullable();
            $table->string('connection_name', 50);
            $table->enum('status', ['committed', 'rolled_back']);
            $table->unsignedInteger('elapsed_ms');
            $table->timestamp('started_at', 3);
            $table->timestamp('completed_at', 3);
            $table->unsignedInteger('total_queries_count');
            $table->unsignedInteger('slow_queries_count')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('route_name', 255)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->index('status', 'idx_status');
            $table->index('elapsed_ms', 'idx_elapsed_ms');
            $table->index('started_at', 'idx_started_at');
            $table->index('user_id', 'idx_user_id');
            $table->index('route_name', 'idx_route_name');
            $table->index('connection_name', 'idx_connection_name');
            $table->index(['status', 'elapsed_ms'], 'idx_status_elapsed');

            if ($isMysql) {
                $table->primary(['id', 'completed_at']);
            }
        });

        Schema::create($queryTable, function (Blueprint $table) use ($logTable, $isMysql): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_log_id');
            $table->text('sql_query');
            $table->unsignedInteger('execution_time_ms');
            $table->string('connection_name', 50);
            $table->unsignedTinyInteger('query_order');

            if (! $isMysql) {
                $table->foreign('transaction_log_id')
                    ->references('id')
                    ->on($logTable)
                    ->onDelete('cascade');
            }

            $table->index('transaction_log_id', 'idx_transaction_log_id');
            $table->index('execution_time_ms', 'idx_execution_time_ms');
        });

        if ($isMysql) {
            $this->addHourlyPartitions($logTable);
        }
    }

    public function down(): void
    {
        $logTable   = $this->resolveTableName('database-transaction-retry.slow_transactions.log_table', 'db_transaction_logs');
        $queryTable = $this->resolveTableName('database-transaction-retry.slow_transactions.query_table', 'db_transaction_queries');

        Schema::dropIfExists($queryTable);
        Schema::dropIfExists($logTable);
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
};

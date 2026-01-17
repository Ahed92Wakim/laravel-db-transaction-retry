<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('transaction_retry_events', function (Blueprint $table) use ($isMysql): void {
            if ($isMysql) {
                $table->unsignedBigInteger('id', true);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            } else {
                $table->bigIncrements('id');
                $table->timestamps();
            }

            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('retry_status', 20)->nullable()->index();
            $table->string('log_level', 20)->nullable()->index();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(0);
            $table->string('trx_label', 120)->nullable()->index();
            $table->string('exception_class', 255)->nullable()->index();
            $table->string('sql_state', 10)->nullable()->index();
            $table->unsignedInteger('driver_code')->nullable()->index();
            $table->string('connection', 100)->nullable();
            $table->longText('raw_sql')->nullable();
            $table->json('error_info')->nullable();
            $table->string('method', 10)->nullable()->index();
            $table->string('route_name', 255)->nullable()->index();
            $table->text('url')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedSmallInteger('auth_header_len')->nullable();
            $table->string('route_hash', 64)->nullable()->index();
            $table->string('query_hash', 64)->nullable()->index();
            $table->string('event_hash', 64)->nullable()->index();
            $table->json('context')->nullable();

            if ($isMysql) {
                $table->primary(['id', 'created_at']);
            }
        });

        if ($isMysql) {
            $this->addHourlyPartitions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_retry_events');
    }

    private function addHourlyPartitions(): void
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
            'transaction_retry_events',
            implode(', ', $partitions)
        );

        DB::statement($sql);
    }
};

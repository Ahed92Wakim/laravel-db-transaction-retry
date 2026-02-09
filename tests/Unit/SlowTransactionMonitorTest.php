<?php

namespace Tests;

use DatabaseTransactions\RetryHelper\Support\SlowTransactionMonitor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Http\Events\RequestHandled;

test('slow transaction monitor logs transaction and slow queries', function (): void {
    $database = new FakeSlowDatabaseManager();
    $this->app->instance('db', $database);
    $this->app->instance('tx.label', 'checkout');

    $monitor = new SlowTransactionMonitor([
        'transaction_threshold_ms' => 0,
        'slow_query_threshold_ms'  => 0,
        'log_table'                => 'db_transaction_logs',
        'query_table'              => 'db_transaction_queries',
        'log_connection'           => null,
        'log_enabled'              => false,
    ]);

    $connection = $database->connection();

    $monitor->handleTransactionBeginning(new TransactionBeginning($connection));
    $monitor->handleQueryExecuted(new QueryExecuted(
        'select * from users where id = ?',
        [5],
        12.5,
        $connection
    ));
    $monitor->handleTransactionCommitted(new TransactionCommitted($connection));

    $logRows = array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'db_transaction_logs'
    ));
    $queryRows = array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'db_transaction_queries'
    ));

    expect($logRows)->toHaveCount(1);
    expect($queryRows)->toHaveCount(1);

    $logRow = $logRows[0]['row'];
    expect($logRow['transaction_label'])->toBe('checkout');
    expect($logRow['status'])->toBe('committed');
    expect($logRow['total_queries_count'])->toBe(1);
    expect($logRow['slow_queries_count'])->toBe(1);

    $queryRow = $queryRows[0]['row'];
    expect($queryRow['transaction_log_id'])->toBe($database->lastInsertId);
    expect($queryRow['execution_time_ms'])->toBe(13);
    expect($queryRow['query_order'])->toBe(1);
});

test('slow transaction monitor updates http status after request handled', function (): void {
    $database = new FakeSlowDatabaseManager(columns: [
        'db_transaction_logs' => ['http_status'],
    ]);
    $this->app->instance('db', $database);

    $monitor = new SlowTransactionMonitor([
        'transaction_threshold_ms' => 0,
        'slow_query_threshold_ms'  => 0,
        'log_table'                => 'db_transaction_logs',
        'query_table'              => 'db_transaction_queries',
        'log_connection'           => null,
        'log_enabled'              => false,
    ]);

    $connection = $database->connection();

    $monitor->handleTransactionBeginning(new TransactionBeginning($connection));
    $monitor->handleTransactionCommitted(new TransactionCommitted($connection));

    $monitor->handleRequestHandled(new RequestHandled(
        new FakeRequest(),
        new FakeResponse(204)
    ));

    expect($database->updates)->toHaveCount(1);
    $update = $database->updates[0];
    expect($update['table'])->toBe('db_transaction_logs');
    expect($update['ids'])->toBe([$database->lastInsertId]);
    expect($update['values']['http_status'])->toBe(204);
});

final class FakeSlowDatabaseManager
{
    /** @var list<array{table:string,row:array}> */
    public array $insertedRows = [];
    /** @var list<array{table:string,ids:array,values:array}> */
    public array $updates    = [];
    public int $lastInsertId = 0;

    private FakeSlowConnection $connection;

    /**
     * @param array<string, list<string>> $columns
     */
    public function __construct(array $columns = [])
    {
        $this->connection = new FakeSlowConnection($this, $columns);
    }

    public function connection(?string $name = null): FakeSlowConnection
    {
        return $this->connection;
    }
}

final class FakeSlowConnection
{
    private FakeSlowSchemaBuilder $schemaBuilder;

    /**
     * @param array<string, list<string>> $columns
     */
    public function __construct(
        private FakeSlowDatabaseManager $manager,
        array $columns = [],
        private string $name = 'default'
    ) {
        $this->schemaBuilder = new FakeSlowSchemaBuilder($columns);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchemaBuilder(): FakeSlowSchemaBuilder
    {
        return $this->schemaBuilder;
    }

    public function table(string $table): FakeSlowTable
    {
        return new FakeSlowTable($this->manager, $table);
    }

    public function query(): FakeSlowQueryBuilder
    {
        return new FakeSlowQueryBuilder();
    }

    public function prepareBindings(array $bindings): array
    {
        return $bindings;
    }
}

final class FakeSlowSchemaBuilder
{
    /**
     * @param array<string, list<string>> $columns
     */
    public function __construct(private array $columns)
    {
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns[$table] ?? [], true);
    }
}

final class FakeSlowTable
{
    private array $whereIds = [];

    public function __construct(
        private FakeSlowDatabaseManager $manager,
        private string $table
    ) {
    }

    public function insertGetId(array $row): int
    {
        $this->manager->lastInsertId++;
        $this->manager->insertedRows[] = ['table' => $this->table, 'row' => $row];

        return $this->manager->lastInsertId;
    }

    public function insert(array $row): bool
    {
        if (array_is_list($row) && isset($row[0]) && is_array($row[0])) {
            foreach ($row as $item) {
                $this->manager->insertedRows[] = ['table' => $this->table, 'row' => $item];
            }

            return true;
        }

        $this->manager->insertedRows[] = ['table' => $this->table, 'row' => $row];

        return true;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->whereIds = $values;

        return $this;
    }

    public function update(array $values): int
    {
        $this->manager->updates[] = [
            'table'  => $this->table,
            'ids'    => $this->whereIds,
            'values' => $values,
        ];

        return 1;
    }
}

final class FakeSlowQueryBuilder
{
    public function getGrammar(): FakeSlowQueryGrammar
    {
        return new FakeSlowQueryGrammar();
    }
}

final class FakeSlowQueryGrammar
{
    public function substituteBindingsIntoRawSql(string $sql, array $bindings): string
    {
        $query = $sql;
        foreach ($bindings as $binding) {
            $quoted = is_numeric($binding) ? (string) $binding : "'" . (string) $binding . "'";
            $query  = preg_replace('/\?/', $quoted, $query, 1);
        }

        return $query;
    }
}

final class FakeRequest
{
}

final class FakeResponse
{
    public function __construct(private int $status)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

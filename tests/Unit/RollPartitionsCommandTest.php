<?php

namespace Tests;

use DatabaseTransactions\RetryHelper\Console\RollPartitionsCommand;
use DateTimeImmutable;
use Symfony\Component\Console\Tester\CommandTester;

test('roll partitions warns when connection is not mysql', function (): void {
    $this->app->instance('db', new FakePartitionDatabaseManager(driver: 'sqlite'));

    $command = new RollPartitionsCommand();
    $command->setLaravel($this->app);

    $tester   = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0);
    expect($tester->getDisplay())->toContain('only supported for MySQL');
});

test('roll partitions fails when table does not exist', function (): void {
    $this->app->instance('db', new FakePartitionDatabaseManager(driver: 'mysql', hasTable: false));

    $command = new RollPartitionsCommand();
    $command->setLaravel($this->app);

    $tester   = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(1);
    expect($tester->getDisplay())->toContain('Table not found');
});

test('roll partitions reorganizes p_max when boundaries are available', function (): void {
    $now        = new DateTimeImmutable('now');
    $boundary   = $now->setTime((int) $now->format('H'), 0, 0);
    $partitions = [
        (object) [
            'PARTITION_NAME'        => 'p_history',
            'PARTITION_DESCRIPTION' => (string) $boundary->getTimestamp(),
            'candidate'             => $boundary->format('Y-m-d H:i:s'),
        ],
        (object) [
            'PARTITION_NAME'        => 'p_max',
            'PARTITION_DESCRIPTION' => 'MAXVALUE',
            'candidate'             => null,
        ],
    ];

    $database = new FakePartitionDatabaseManager(driver: 'mysql', hasTable: true, partitions: $partitions);
    $this->app->instance('db', $database);

    $command = new RollPartitionsCommand();
    $command->setLaravel($this->app);

    $tester   = new CommandTester($command);
    $exitCode = $tester->execute(['--hours' => 1]);

    expect($exitCode)->toBe(0);
    expect($database->statements)->toHaveCount(1);

    $statement = $database->statements[0][0];
    expect($statement)->toContain('REORGANIZE PARTITION p_max INTO');
    expect($statement)->toContain('PARTITION p_max VALUES LESS THAN (MAXVALUE)');
});

final class FakePartitionDatabaseManager
{
    /** @var list<array{0:string,1:array}> */
    public array $statements = [];
    /** @var list<array{0:string,1:array}> */
    public array $selectCalls = [];
    /** @var list<object> */
    public array $partitions;

    private FakePartitionConnection $connection;

    /**
     * @param list<object> $partitions
     */
    public function __construct(
        string $driver = 'mysql',
        bool $hasTable = true,
        array $partitions = []
    ) {
        $this->partitions = $partitions;
        $this->connection = new FakePartitionConnection($driver, $hasTable);
    }

    public function connection(?string $name = null): FakePartitionConnection
    {
        return $this->connection;
    }

    public function select(string $query, array $bindings = []): array
    {
        $this->selectCalls[] = [$query, $bindings];

        return $this->partitions;
    }

    public function statement(string $query, array $bindings = []): bool
    {
        $this->statements[] = [$query, $bindings];

        return true;
    }
}

final class FakePartitionConnection
{
    private FakePartitionSchemaBuilder $schemaBuilder;

    public function __construct(
        private string $driver,
        bool $hasTable,
        private string $databaseName = 'testing',
        private string $name = 'default'
    ) {
        $this->schemaBuilder = new FakePartitionSchemaBuilder($hasTable);
    }

    public function getDriverName(): string
    {
        return $this->driver;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchemaBuilder(): FakePartitionSchemaBuilder
    {
        return $this->schemaBuilder;
    }
}

final class FakePartitionSchemaBuilder
{
    public function __construct(private bool $hasTable)
    {
    }

    public function hasTable(string $table): bool
    {
        return $this->hasTable;
    }
}

<?php

namespace Tests;

use DatabaseTransactions\RetryHelper\Providers\DatabaseTransactionRetryServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

beforeEach(function (): void {
    $this->database = new FakeDatabaseManagerForExceptions();
    $this->handler  = new FakeExceptionHandler();

    $this->app->instance('db', $this->database);
    $this->app->instance(ExceptionHandler::class, $this->handler);
});

test('logs query exceptions to the database through the service provider', function (): void {
    bootProvider($this->app);

    $this->handler->report(makeQueryExceptionForLogging(1213, '40001'));

    expect($this->database->insertedRows)->toHaveCount(1);

    $insert = $this->database->insertedRows[0];

    expect($insert['table'])->toBe('db_exceptions');
    expect($insert['row']['exception_class'])->toBe(QueryException::class);
    expect($insert['row']['driver_code'])->toBe(1213);
    expect($insert['row']['sql_state'])->toBe('40001');
    expect($insert['row']['raw_sql'])->toContain("'baz'");
    expect($insert['row']['event_hash'])->not->toBeNull();
});

test('does not log query exceptions when exception logging is disabled', function (): void {
    $this->app->make('config')->set('database-transaction-retry.exception_logging.enabled', false);

    bootProvider($this->app);

    $this->handler->report(makeQueryExceptionForLogging(1213, '40001'));

    expect($this->database->insertedRows)->toBe([]);
});

test('ignores non query exceptions', function (): void {
    bootProvider($this->app);

    $this->handler->report(new RuntimeException('boom'));

    expect($this->database->insertedRows)->toBe([]);
});

function bootProvider(TestApplication $app): void
{
    $provider = new DatabaseTransactionRetryServiceProvider($app);
    $provider->register();
    $provider->boot();
}

function makeQueryExceptionForLogging(int $driverCode, string|int $sqlState = 40001): QueryException
{
    $sqlStateString = strtoupper((string) $sqlState);

    if (strlen($sqlStateString) < 5) {
        $sqlStateString = str_pad($sqlStateString, 5, '0', STR_PAD_LEFT);
    }

    $pdo = new \PDOException(
        'SQLSTATE[' . $sqlStateString . ']: Driver error',
        is_numeric($sqlState) ? (int) $sqlState : 0
    );
    $pdo->errorInfo = [$sqlStateString, $driverCode, 'Driver error'];

    return new QueryException(
        'mysql',
        'insert into foo (bar) values (?)',
        ['baz'],
        $pdo
    );
}

final class FakeExceptionHandler implements ExceptionHandler
{
    /** @var list<\Closure> */
    private array $reportables = [];

    public function report(Throwable $e): void
    {
        foreach ($this->reportables as $reportable) {
            if (! $this->supports($reportable, $e)) {
                continue;
            }

            $reportable($e);
        }
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e): void
    {
        return;
    }

    public function renderForConsole($output, Throwable $e): void
    {
        // no-op
    }

    public function reportable(\Closure $callback): void
    {
        $this->reportables[] = $callback;
    }

    private function supports(\Closure $callback, Throwable $throwable): bool
    {
        $reflection = new \ReflectionFunction($callback);
        $parameter  = $reflection->getParameters()[0] ?? null;

        if ($parameter === null) {
            return true;
        }

        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return true;
        }

        $class = $type->getName();

        return $throwable instanceof $class;
    }
}

final class FakeDatabaseManagerForExceptions
{
    /** @var list<array{table:string,row:array}> */
    public array $insertedRows = [];
    private FakeConnectionForExceptions $connection;

    public function __construct()
    {
        $this->connection = new FakeConnectionForExceptions($this);
    }

    public function connection(?string $name = null): FakeConnectionForExceptions
    {
        return $this->connection;
    }

    public function table(string $table): FakeTableForExceptions
    {
        return new FakeTableForExceptions($this, $table);
    }
}

final class FakeConnectionForExceptions
{
    private FakeDatabaseManagerForExceptions $manager;
    private FakeQueryGrammarForExceptions $grammar;

    public function __construct(
        FakeDatabaseManagerForExceptions $manager,
        ?FakeQueryGrammarForExceptions $grammar = null
    ) {
        $this->manager = $manager;
        $this->grammar = $grammar ?? new FakeQueryGrammarForExceptions();
    }

    public function getQueryGrammar(): FakeQueryGrammarForExceptions
    {
        return $this->grammar;
    }

    public function table(string $table): FakeTableForExceptions
    {
        return new FakeTableForExceptions($this->manager, $table);
    }
}

final class FakeQueryGrammarForExceptions
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

final class FakeTableForExceptions
{
    public function __construct(
        private FakeDatabaseManagerForExceptions $manager,
        private string $table
    ) {
    }

    public function insert(array $row): bool
    {
        $this->manager->insertedRows[] = ['table' => $this->table, 'row' => $row];

        return true;
    }
}

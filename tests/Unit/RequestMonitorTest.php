<?php

namespace Tests;

use DatabaseTransactions\RetryHelper\Support\RequestMonitor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

test('request monitor logs request and queries', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $startTime = microtime(true) - 0.05;
    $request   = new FakeRequestLogRequest(
        server: ['REQUEST_TIME_FLOAT' => $startTime],
        method: 'POST',
        route: new FakeRoute('orders/{order}', 'orders.show'),
        ip: '127.0.0.1',
        path: 'orders/5'
    );
    $this->app->instance('request', $request);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $connection = $database->connection();

    $monitor->handleQueryExecuted(new QueryExecuted(
        'select * from users where id = ?',
        [5],
        12.5,
        $connection
    ));

    $monitor->handleRequestHandled(new RequestHandled(
        $request,
        new FakeRequestLogResponse(201)
    ));

    $logRows = array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'db_request_logs'
    ));
    $queryRows = array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'db_query_logs'
    ));

    expect($logRows)->toHaveCount(1);
    expect($queryRows)->toHaveCount(1);

    $logRow = $logRows[0]['row'];
    expect($logRow['total_queries_count'])->toBe(1);
    expect($logRow['http_method'])->toBe('POST');
    expect($logRow['route_name'])->toBe('orders.show');
    expect($logRow['url'])->toBe('orders/{order}');
    expect($logRow['ip_address'])->toBe('127.0.0.1');
    expect($logRow['http_status'])->toBe(201);
    expect($logRow['started_at'])->not->toBeNull();
    expect($logRow['completed_at'])->not->toBeNull();

    $queryRow = $queryRows[0]['row'];
    expect($queryRow['loggable_id'])->toBe($database->lastInsertId);
    expect($queryRow['loggable_type'])->toBe('db_request_logs');
    expect($queryRow['execution_time_ms'])->toBe(13);
    expect($queryRow['query_order'])->toBe(1);
});

test('request monitor skips requests without queries', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $request = new FakeRequestLogRequest(path: 'orders');
    $this->app->instance('request', $request);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $monitor->handleRequestHandled(new RequestHandled(
        $request,
        new FakeRequestLogResponse(204)
    ));

    expect($database->insertedRows)->toBe([]);
});

test('request monitor logs command queries', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $input  = new ArrayInput([]);
    $output = new NullOutput();

    $monitor->handleCommandStarting(new CommandStarting('inspire', $input, $output));

    $connection = $database->connection();
    $monitor->handleQueryExecuted(new QueryExecuted(
        'select * from users',
        [],
        4.2,
        $connection
    ));

    $monitor->handleCommandFinished(new CommandFinished('inspire', $input, $output, 0));

    $logRows = array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'db_request_logs'
    ));

    expect($logRows)->toHaveCount(1);

    $logRow = $logRows[0]['row'];
    expect($logRow['http_method'])->toBe('CLI');
    expect($logRow['route_name'])->toBe('inspire');
    expect($logRow['url'])->toBe('inspire');
    expect($logRow['http_status'])->toBeNull();
});

test('request monitor ignores queue commands', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $input  = new ArrayInput([]);
    $output = new NullOutput();

    foreach (['queue:work', 'queue:listen', 'queue:retry'] as $command) {
        $monitor->handleCommandStarting(new CommandStarting($command, $input, $output));

        $connection = $database->connection();
        $monitor->handleQueryExecuted(new QueryExecuted(
            'select * from jobs where reserved_at is null',
            [],
            4.2,
            $connection
        ));

        $monitor->handleCommandFinished(new CommandFinished($command, $input, $output, 0));
    }

    expect($database->insertedRows)->toBe([]);
});

test('request monitor ignores package table queries', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $request = new FakeRequestLogRequest(path: 'orders');
    $this->app->instance('request', $request);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $connection = $database->connection();
    $monitor->handleQueryExecuted(new QueryExecuted(
        'insert into db_transaction_logs (status) values (?)',
        ['committed'],
        2.2,
        $connection
    ));

    $monitor->handleRequestHandled(new RequestHandled(
        $request,
        new FakeRequestLogResponse(200)
    ));

    expect($database->insertedRows)->toBe([]);
});

test('request monitor ignores package dashboard and api requests', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $connection = $database->connection();

    $dashboardRequest = new FakeRequestLogRequest(path: 'transaction-retry');
    $this->app->instance('request', $dashboardRequest);

    $monitor->handleQueryExecuted(new QueryExecuted(
        'select * from users',
        [],
        1.2,
        $connection
    ));

    $monitor->handleRequestHandled(new RequestHandled(
        $dashboardRequest,
        new FakeRequestLogResponse(200)
    ));

    $apiRequest = new FakeRequestLogRequest(path: 'api/transaction-retry/requests');
    $this->app->instance('request', $apiRequest);

    $monitor->handleQueryExecuted(new QueryExecuted(
        'select * from users',
        [],
        1.2,
        $connection
    ));

    $monitor->handleRequestHandled(new RequestHandled(
        $apiRequest,
        new FakeRequestLogResponse(200)
    ));

    expect($database->insertedRows)->toBe([]);
});

test('request monitor does not recurse while resolving the authenticated user', function (): void {
    $database = new FakeRequestDatabaseManager();
    $this->app->instance('db', $database);

    $monitor    = null;
    $connection = $database->connection();

    $request = new FakeRequestLogRequest(
        path: 'orders/9',
        userResolver: function () use (&$monitor, $connection): mixed {
            if (! $monitor instanceof RequestMonitor) {
                return null;
            }

            $monitor->handleQueryExecuted(new QueryExecuted(
                'select * from users where id = ?',
                [42],
                3.5,
                $connection
            ));

            return null;
        }
    );

    $this->app->instance('request', $request);

    $monitor = new RequestMonitor([
        'enabled'        => true,
        'log_table'      => 'db_request_logs',
        'query_table'    => 'db_query_logs',
        'log_connection' => null,
    ]);

    $monitor->handleQueryExecuted(new QueryExecuted(
        'select * from orders where id = ?',
        [9],
        8.2,
        $connection
    ));

    $monitor->handleRequestHandled(new RequestHandled(
        $request,
        new FakeRequestLogResponse(200)
    ));

    $queryRows = array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'db_query_logs'
    ));

    expect($request->userCallCount)->toBe(1);
    expect($queryRows)->toHaveCount(1);
    expect($queryRows[0]['row']['sql_query'])->toBe('select * from orders where id = ?');
});

final class FakeRequestDatabaseManager
{
    /** @var list<array{table:string,row:array}> */
    public array $insertedRows = [];
    public int $lastInsertId   = 0;

    private FakeRequestConnection $connection;

    public function __construct()
    {
        $this->connection = new FakeRequestConnection($this);
    }

    public function connection(?string $name = null): FakeRequestConnection
    {
        return $this->connection;
    }
}

final class FakeRequestConnection
{
    public function __construct(
        private FakeRequestDatabaseManager $manager,
        private string $name = 'default'
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function query(): FakeRequestQueryBuilder
    {
        return new FakeRequestQueryBuilder();
    }

    public function prepareBindings(array $bindings): array
    {
        return $bindings;
    }

    public function table(string $table): FakeRequestTable
    {
        return new FakeRequestTable($this->manager, $table);
    }
}

final class FakeRequestQueryBuilder
{
    public function getGrammar(): FakeRequestQueryGrammar
    {
        return new FakeRequestQueryGrammar();
    }
}

final class FakeRequestQueryGrammar
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

final class FakeRequestTable
{
    public function __construct(
        private FakeRequestDatabaseManager $manager,
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
}

final class FakeRequestLogRequest
{
    public int $userCallCount = 0;

    public function __construct(
        private array $server = [],
        private string $method = 'GET',
        private ?FakeRoute $route = null,
        private ?string $ip = '127.0.0.1',
        private string $path = '',
        private mixed $userResolver = null
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function route(): ?FakeRoute
    {
        return $this->route;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function path(): string
    {
        return trim($this->path, '/');
    }

    public function server(string $key): mixed
    {
        return $this->server[$key] ?? null;
    }

    public function user(): mixed
    {
        $this->userCallCount++;

        if (is_callable($this->userResolver)) {
            return ($this->userResolver)();
        }

        return null;
    }
}

final class FakeRoute
{
    public function __construct(
        private string $uri,
        private ?string $name = null
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}

final class FakeRequestLogResponse
{
    public function __construct(private int $status)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

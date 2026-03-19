<?php

namespace DatabaseTransactions\RetryHelper;

function sleep(int $seconds): void
{
    \Tests\SleepSpy::record($seconds);
}

namespace Tests;

use DatabaseTransactions\RetryHelper\Console\StartRetryCommand;
use DatabaseTransactions\RetryHelper\Console\StopRetryCommand;
use DatabaseTransactions\RetryHelper\Providers\DbMacroServiceProvider;
use DatabaseTransactions\RetryHelper\Services\TransactionRetrier;
use DatabaseTransactions\RetryHelper\Support\RetryToggle;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Macroable;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    FakeDatabaseManager::flushMacros();

    $this->database = new FakeDatabaseManager();

    $this->app->instance('db', $this->database);

    SleepSpy::reset();
    RetryToggle::enable();
});

test('returns callback result without retries', function (): void {
    $result = TransactionRetrier::runWithRetry(fn () => 'done');

    expect($result)->toBe('done');
    expect($this->database->transactionCalls)->toBe(1);
    expect(retryEventRows($this->database))->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

test('bypasses retry logic when disabled', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.enabled',
        false
    );

    $attempts = 0;

    try {
        TransactionRetrier::runWithRetry(function () use (&$attempts): void {
            $attempts++;

            throw makeQueryException(1213);
        }, maxRetries: 3, retryDelay: 1, trxLabel: 'disabled');

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(1213);
    }

    expect($attempts)->toBe(1);
    expect($this->database->transactionCalls)->toBe(1);
    expect(retryEventRows($this->database))->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

test('bypasses retry logic when persisted marker exists', function (): void {
    expect(RetryToggle::disable())->toBeTrue();
    expect(is_file(RetryToggle::markerPath()))->toBeTrue();

    $attempts = 0;

    try {
        TransactionRetrier::runWithRetry(function () use (&$attempts): void {
            $attempts++;

            throw makeQueryException(1213);
        }, maxRetries: 3, retryDelay: 1, trxLabel: 'disabled-marker');

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(1213);
    } finally {
        RetryToggle::enable();
    }

    expect($attempts)->toBe(1);
    expect($this->database->transactionCalls)->toBe(1);
    expect(retryEventRows($this->database))->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
    expect(is_file(RetryToggle::markerPath()))->toBeFalse();
});

test('retries on deadlock and persists attempt and success rows', function (): void {
    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeQueryException(1213);
        }

        return 'recovered';
    }, maxRetries: 3, retryDelay: 1, trxLabel: 'orders');

    expect($result)->toBe('recovered');
    expect($this->database->transactionCalls)->toBe(2);
    expect(SleepSpy::$delays)->toHaveCount(1);
    expect(SleepSpy::$delays[0])->toBe(1);

    $rows = retryEventRows($this->database);
    expect($rows)->toHaveCount(2);

    $attemptRow = $rows[0]['row'];
    expect($attemptRow['retry_status'])->toBe('attempt');
    expect($attemptRow['log_level'])->toBe('warning');
    expect($attemptRow['attempt'])->toBe(1);
    expect($attemptRow['max_retries'])->toBe(3);
    expect($attemptRow['trx_label'])->toBe('orders');
    expect($attemptRow['retry_group_id'])->toBeString()->not->toBe('');
    expect($attemptRow['exception_class'])->toBe(QueryException::class);
    expect($attemptRow['sql_state'])->toBe('40001');
    expect($attemptRow['driver_code'])->toBe(1213);

    $successRow = $rows[1]['row'];
    expect($successRow['retry_status'])->toBe('success');
    expect($successRow['log_level'])->toBe('warning');
    expect($successRow['retry_group_id'])->toBe($attemptRow['retry_group_id']);
});

test('persists retry events to database', function (): void {
    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeQueryException(1213);
        }

        return 'ok';
    }, maxRetries: 2, retryDelay: 1, trxLabel: 'db-log');

    expect($result)->toBe('ok');
    expect($this->database->insertedRows)->toHaveCount(2);

    $attemptInsert = $this->database->insertedRows[0];
    expect($attemptInsert['table'])->toBe('transaction_retry_events');
    expect($attemptInsert['row']['retry_status'])->toBe('attempt');
    expect($attemptInsert['row']['log_level'])->toBe('warning');
    expect($attemptInsert['row']['trx_label'])->toBe('db-log');
    expect($attemptInsert['row']['retry_group_id'])->toBeString()->not->toBe('');
    expect($attemptInsert['row']['exception_class'])->toBe(QueryException::class);
    expect($attemptInsert['row']['driver_code'])->toBe(1213);
    expect($attemptInsert['row']['sql_state'])->toBe('40001');

    $successInsert = $this->database->insertedRows[1];
    expect($successInsert['row']['retry_status'])->toBe('success');
    expect($successInsert['row']['log_level'])->toBe('warning');
    expect($successInsert['row']['retry_group_id'])->toBe($attemptInsert['row']['retry_group_id']);
});

test('uses configured success log level for retry logging', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.logging.levels',
        ['success' => 'notice', 'failure' => 'alert']
    );

    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeQueryException(1213);
        }

        return 'ok';
    }, maxRetries: 2, retryDelay: 1, trxLabel: 'level-success');

    expect($result)->toBe('ok');
    $rows = retryEventRows($this->database);
    expect($rows)->toHaveCount(2);
    expect($rows[0]['row']['log_level'])->toBe('warning');
    expect($rows[1]['row']['log_level'])->toBe('notice');
});

test('uses configured failure log level for retry logging', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.logging.levels',
        ['success' => 'info', 'failure' => 'critical']
    );

    try {
        TransactionRetrier::runWithRetry(function (): void {
            throw makeQueryException(1213);
        }, maxRetries: 2, retryDelay: 1, trxLabel: 'level-failure');

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1213);
    }

    $rows = retryEventRows($this->database);
    expect($rows)->toHaveCount(3);
    expect($rows[0]['row']['log_level'])->toBe('warning');
    expect($rows[1]['row']['log_level'])->toBe('warning');
    expect($rows[2]['row']['log_level'])->toBe('critical');
});

test('throws after max retries and persists failure row', function (): void {
    try {
        TransactionRetrier::runWithRetry(function (): void {
            throw makeQueryException(1213);
        }, maxRetries: 3, retryDelay: 1, trxLabel: 'payments');

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(1213);
    }

    expect($this->database->transactionCalls)->toBe(3);
    expect(SleepSpy::$delays)->toHaveCount(2);
    expect(SleepSpy::$delays[0])->toBe(1);
    expect(SleepSpy::$delays[1])->toBeGreaterThanOrEqual(1);
    expect(SleepSpy::$delays[1])->toBeLessThanOrEqual(3);

    $rows = retryEventRows($this->database);
    expect($rows)->toHaveCount(4);

    $failureRow = $rows[3]['row'];
    expect($failureRow['retry_status'])->toBe('failure');
    expect($failureRow['log_level'])->toBe('error');
    expect($failureRow['attempt'])->toBe(3);
    expect($failureRow['max_retries'])->toBe(3);
    expect($failureRow['trx_label'])->toBe('payments');
    expect($failureRow['exception_class'])->toBe(QueryException::class);
    expect($failureRow['sql_state'])->toBe('40001');
    expect($failureRow['driver_code'])->toBe(1213);
});

test('db facade macro delegates to transaction retrier', function (): void {
    $provider = new DbMacroServiceProvider($this->app);
    $provider->boot();

    $attempts = 0;

    expect(FakeDatabaseManager::hasMacro('transactionWithRetry'))->toBeTrue();

    $result = DB::transactionWithRetry(function () use (&$attempts) {
        $attempts++;

        return 'macro-done';
    }, trxLabel: 'macro-test');

    expect($result)->toBe('macro-done');
    expect($attempts)->toBe(1);
    expect($this->database->transactionCalls)->toBe(1);
    expect($this->app->make('tx.label'))->toBe('macro-test');
});

test('does not retry for non deadlock query exception', function (): void {
    try {
        TransactionRetrier::runWithRetry(function (): void {
            throw makeQueryException(999, 0);
        }, maxRetries: 3, retryDelay: 1);

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(999);
    }

    expect($this->database->transactionCalls)->toBe(1);
    expect(retryEventRows($this->database))->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

test('does not retry when deadlock retry disabled', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.retry_on_deadlock',
        false
    );

    $attempts = 0;

    try {
        TransactionRetrier::runWithRetry(function () use (&$attempts): void {
            $attempts++;

            throw makeQueryException(1213);
        }, maxRetries: 3, retryDelay: 1, trxLabel: 'deadlock-disabled');

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(1213);
    }

    expect($attempts)->toBe(1);
    expect($this->database->transactionCalls)->toBe(1);
    expect(SleepSpy::$delays)->toBe([]);
    expect(retryEventRows($this->database))->toBe([]);
});

test('retries on lock wait timeout and applies configured session timeout', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.lock_wait_timeout_seconds',
        7
    );

    Container::getInstance()->make('config')->set(
        'database-transaction-retry.retry_on_lock_wait_timeout',
        true
    );

    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeQueryException(1205, 'HY000');
        }

        return 'done';
    }, maxRetries: 3, retryDelay: 1, trxLabel: 'lock-wait');

    expect($result)->toBe('done');
    expect($this->database->transactionCalls)->toBe(2);
    expect(SleepSpy::$delays)->toHaveCount(1);
    expect($this->database->statementCalls)->toHaveCount(2);

    [$statement, $bindings] = $this->database->statementCalls[0];
    expect($statement)->toBe('SET SESSION innodb_lock_wait_timeout = ?');
    expect($bindings)->toBe([7]);

    $rows = retryEventRows($this->database);
    expect($rows)->toHaveCount(2);
    expect($rows[0]['row']['driver_code'])->toBe(1205);
    expect($rows[0]['row']['sql_state'])->toBe('HY000');
});

test('does not change session timeout when lock wait retry disabled', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.lock_wait_timeout_seconds',
        9
    );

    Container::getInstance()->make('config')->set(
        'database-transaction-retry.retry_on_lock_wait_timeout',
        false
    );

    try {
        TransactionRetrier::runWithRetry(function (): void {
            throw makeQueryException(1205, 'HY000');
        }, maxRetries: 2, retryDelay: 1);

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(1205);
    }

    expect($this->database->statementCalls)->toBe([]);
});

test('retries when SQLSTATE indicates deadlock even without driver code', function (): void {
    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeSqlStateOnlyQueryException('40001');
        }

        return 'ok';
    }, maxRetries: 3, retryDelay: 1, trxLabel: 'sqlstate-deadlock');

    expect($result)->toBe('ok');
    expect($this->database->transactionCalls)->toBe(2);
    $rows = retryEventRows($this->database);
    expect($rows)->toHaveCount(2);
    expect($rows[0]['row']['sql_state'])->toBe('40001');
    expect($rows[0]['row']['driver_code'])->toBeNull();
});

test('binds transaction label into container during execution', function (): void {
    $captured = null;

    TransactionRetrier::runWithRetry(function () use (&$captured) {
        $captured = app()->make('tx.label');

        return 'done';
    }, trxLabel: 'orders-sync');

    expect($captured)->toBe('orders-sync');
    expect(app()->make('tx.label'))->toBe('orders-sync');
});

test('does not bind empty transaction label', function (): void {
    TransactionRetrier::runWithRetry(fn () => 'ok', trxLabel: '');

    expect(app()->bound('tx.label'))->toBeFalse();
});

test('detects explicitly disabled configuration values', function (mixed $value, bool $expected): void {
    expect(RetryToggle::isExplicitlyDisabledValue($value))->toBe($expected);
})->with([
    'boolean false' => [false, true],
    'boolean true'  => [true, false],
    'string false'  => ['false', true],
    'string true'   => ['true', false],
    'numeric zero'  => [0, true],
    'numeric one'   => [1, false],
    'empty string'  => ['', false],
    'null value'    => [null, false],
    'off keyword'   => ['off', true],
    'yes keyword'   => ['yes', false],
]);

test('start command enables retries and removes marker', function (): void {
    RetryToggle::disable();
    expect(is_file(RetryToggle::markerPath()))->toBeTrue();

    try {
        $command = new StartRetryCommand();
        $command->setLaravel($this->app);

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        expect($exitCode)->toBe(0);

        $config = Container::getInstance()->make('config');
        expect($config->get('database-transaction-retry.enabled'))->toBeTrue();
        expect(is_file(RetryToggle::markerPath()))->toBeFalse();
        expect($tester->getDisplay())->toContain('Database transaction retries have been enabled.');
        expect($tester->getDisplay())->toContain('Current status: ENABLED');
    } finally {
        RetryToggle::enable();
    }
});

test('start command honours explicitly disabled configuration', function (): void {
    Container::getInstance()->make('config')->set('database-transaction-retry.enabled', false);

    try {
        $command = new StartRetryCommand();
        $command->setLaravel($this->app);

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        expect($exitCode)->toBe(0);
        $config = Container::getInstance()->make('config');
        expect($config->get('database-transaction-retry.enabled'))->toBeFalse();
        expect(RetryToggle::isEnabled($config->get('database-transaction-retry')))->toBeFalse();
        expect($tester->getDisplay())->toContain('Base configuration keeps retries disabled');
    } finally {
        RetryToggle::enable();
    }
});

test('stop command disables retries and creates marker', function (): void {
    RetryToggle::enable();
    if (is_file(RetryToggle::markerPath())) {
        unlink(RetryToggle::markerPath());
    }

    $command = new StopRetryCommand();
    $command->setLaravel($this->app);

    $tester   = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0);
    $config = Container::getInstance()->make('config');
    expect($config->get('database-transaction-retry.enabled'))->toBeFalse();
    expect(is_file(RetryToggle::markerPath()))->toBeTrue();
    expect($tester->getDisplay())->toContain('Database transaction retries have been disabled.');
    expect($tester->getDisplay())->toContain('Current status: DISABLED');

    RetryToggle::enable();
});

test('stop command honours explicitly disabled configuration', function (): void {
    Container::getInstance()->make('config')->set('database-transaction-retry.enabled', false);

    $command = new StopRetryCommand();
    $command->setLaravel($this->app);

    $tester   = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0);
    $config = Container::getInstance()->make('config');
    expect($config->get('database-transaction-retry.enabled'))->toBeFalse();
    expect($tester->getDisplay())->toContain('Base configuration already disables retries');
    expect(is_file(RetryToggle::markerPath()))->toBeFalse();

    RetryToggle::enable();
});

function makeQueryException(int $driverCode, string|int $sqlState = 40001): QueryException
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

function makeSqlStateOnlyQueryException(string|int $sqlState = 40001): QueryException
{
    $sqlStateString = strtoupper((string) $sqlState);

    if (strlen($sqlStateString) < 5) {
        $sqlStateString = str_pad($sqlStateString, 5, '0', STR_PAD_LEFT);
    }

    $pdo = new \PDOException(
        'SQLSTATE[' . $sqlStateString . ']: Driver error',
        is_numeric($sqlState) ? (int) $sqlState : 0
    );
    $pdo->errorInfo = [$sqlStateString, null, 'Driver error'];

    return new QueryException(
        'mysql',
        'insert into foo (bar) values (?)',
        ['baz'],
        $pdo
    );
}

function retryEventRows(FakeDatabaseManager $database): array
{
    return array_values(array_filter(
        $database->insertedRows,
        static fn (array $row): bool => $row['table'] === 'transaction_retry_events'
    ));
}

final class FakeDatabaseManager
{
    use Macroable;

    public int $transactionCalls = 0;
    /** @var list<array{0:string,1:array}> */
    public array $statementCalls = [];
    /** @var list<array{table:string,row:array}> */
    public array $insertedRows = [];
    private FakeConnection $connection;

    public function __construct(?FakeConnection $connection = null)
    {
        $this->connection = $connection ?? new FakeConnection();
    }

    public function transaction(callable $callback): mixed
    {
        $this->transactionCalls++;

        return $callback();
    }

    public function connection(?string $name = null): FakeConnection
    {
        return $this->connection;
    }

    public function statement(string $query, array $bindings = []): bool
    {
        $this->statementCalls[] = [$query, $bindings];

        return $this->connection()->statement($query, $bindings);
    }

    public function table(string $table): FakeTable
    {
        return new FakeTable($this, $table);
    }

    public function getConnection(): FakeConnection
    {
        return $this->connection;
    }
}

final class FakeConnection
{
    private FakeQueryGrammar $grammar;
    /** @var list<array{0:string,1:array}> */
    public array $statements = [];

    public function __construct(?FakeQueryGrammar $grammar = null)
    {
        $this->grammar = $grammar ?? new FakeQueryGrammar();
    }

    public function getQueryGrammar(): FakeQueryGrammar
    {
        return $this->grammar;
    }

    public function statement(string $query, array $bindings = []): bool
    {
        $this->statements[] = [$query, $bindings];

        return true;
    }
}

final class FakeQueryGrammar
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

final class FakeTable
{
    public function __construct(
        private FakeDatabaseManager $manager,
        private string $table
    ) {
    }

    public function insert(array $row): bool
    {
        if ($row === []) {
            return true;
        }

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

final class SleepSpy
{
    /** @var list<int> */
    public static array $delays = [];

    public static function record(int $seconds): void
    {
        self::$delays[] = $seconds;
    }

    public static function reset(): void
    {
        self::$delays = [];
    }
}

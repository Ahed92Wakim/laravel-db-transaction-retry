<?php

namespace MysqlDeadlocks\RetryHelper;

function sleep(int $seconds): void
{
    \Tests\SleepSpy::record($seconds);
}

namespace Tests;

use Illuminate\Database\QueryException;
use MysqlDeadlocks\RetryHelper\DBTransactionRetryHelper;

beforeEach(function () {
    $this->database   = new FakeDatabaseManager();
    $this->logManager = new FakeLogManager();

    $this->app->instance('db', $this->database);
    $this->app->instance('log', $this->logManager);

    SleepSpy::reset();
});

test('returns callback result without retries', function () {
    $result = DBTransactionRetryHelper::transactionWithRetry(fn () => 'done');

    expect($result)->toBe('done');
    expect($this->database->transactionCalls)->toBe(1);
    expect($this->logManager->records)->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

test('retries on deadlock and logs warning', function () {
    $attempts = 0;

    $result = DBTransactionRetryHelper::transactionWithRetry(function () use (&$attempts) {
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

    expect($this->logManager->records)->toHaveCount(1);
    $record = $this->logManager->records[0];

    expect($record['level'])->toBe('warning');
    expect($record['message'])->toBe('[orders] [MYSQL DEADLOCK RETRY - SUCCESS] After (Attempts: 1/3) - Warning');
    expect($record['context']['attempt'])->toBe(1);
    expect($record['context']['maxRetries'])->toBe(3);
    expect($record['context']['trxLabel'])->toBe('orders');
});

test('throws after max retries and logs error', function () {
    try {
        DBTransactionRetryHelper::transactionWithRetry(function (): void {
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

    expect($this->logManager->records)->toHaveCount(1);
    $record = $this->logManager->records[0];

    expect($record['level'])->toBe('error');
    expect($record['message'])->toBe('[payments] [MYSQL DEADLOCK RETRY - FAILED] After (Attempts: 3/3) - Error');
    expect($record['context']['attempt'])->toBe(3);
    expect($record['context']['maxRetries'])->toBe(3);
    expect($record['context']['trxLabel'])->toBe('payments');
});

test('does not retry for non deadlock query exception', function () {
    try {
        DBTransactionRetryHelper::transactionWithRetry(function (): void {
            throw makeQueryException(999, 0);
        }, maxRetries: 3, retryDelay: 1);

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $th) {
        expect($th->errorInfo[1])->toBe(999);
    }

    expect($this->database->transactionCalls)->toBe(1);
    expect($this->logManager->records)->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

function makeQueryException(int $driverCode, int $sqlState = 40001): QueryException
{
    $sqlStateString = str_pad((string) $sqlState, 5, '0', STR_PAD_LEFT);
    $pdo            = new \PDOException('SQLSTATE[' . $sqlStateString . ']: Driver error', $sqlState);
    $pdo->errorInfo = [$sqlStateString, $driverCode, 'Driver error'];

    return new QueryException(
        'mysql',
        'insert into foo (bar) values (?)',
        ['baz'],
        $pdo
    );
}

final class FakeDatabaseManager
{
    public int $transactionCalls = 0;
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
}

final class FakeConnection
{
    private FakeQueryGrammar $grammar;

    public function __construct(?FakeQueryGrammar $grammar = null)
    {
        $this->grammar = $grammar ?? new FakeQueryGrammar();
    }

    public function getQueryGrammar(): FakeQueryGrammar
    {
        return $this->grammar;
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

final class FakeLogManager
{
    public array $records = [];

    public function build(array $config): FakeLogger
    {
        return new FakeLogger($this);
    }
}

final class FakeLogger
{
    public function __construct(private FakeLogManager $manager)
    {
    }

    public function warning(string $message, array $context = []): void
    {
        $this->manager->records[] = [
            'level'   => 'warning',
            'message' => $message,
            'context' => $context,
        ];
    }

    public function error(string $message, array $context = []): void
    {
        $this->manager->records[] = [
            'level'   => 'error',
            'message' => $message,
            'context' => $context,
        ];
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

<?php

namespace DatabaseTransactions\RetryHelper;

function sleep(int $seconds): void
{
    \Tests\SleepSpy::record($seconds);
}

namespace Tests;

use DatabaseTransactions\RetryHelper\Services\TransactionRetrier;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Psr\Log\AbstractLogger;

beforeEach(function (): void {
    $this->database   = new FakeDatabaseManager();
    $this->logManager = new FakeLogManager();

    $this->app->instance('db', $this->database);
    $this->app->instance('log', $this->logManager);

    SleepSpy::reset();
});

test('returns callback result without retries', function (): void {
    $result = TransactionRetrier::runWithRetry(fn () => 'done');

    expect($result)->toBe('done');
    expect($this->database->transactionCalls)->toBe(1);
    expect($this->logManager->records)->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

test('retries on deadlock and logs warning', function (): void {
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

    expect($this->logManager->records)->toHaveCount(1);
    $record = $this->logManager->records[0];

    expect($record['level'])->toBe('warning');
    expect($record['message'])->toBe('[orders] [DATABASE TRANSACTION RETRY - SUCCESS] Illuminate\Database\QueryException (SQLSTATE 40001, Driver 1213) After (Attempts: 1/3) - Warning');
    expect($record['context']['attempt'])->toBe(1);
    expect($record['context']['maxRetries'])->toBe(3);
    expect($record['context']['trxLabel'])->toBe('orders');
    expect($record['context']['exceptionClass'])->toBe(QueryException::class);
    expect($record['context']['sqlState'])->toBe('40001');
    expect($record['context']['driverCode'])->toBe(1213);
});

test('throws after max retries and logs error', function (): void {
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

    expect($this->logManager->records)->toHaveCount(1);
    $record = $this->logManager->records[0];

    expect($record['level'])->toBe('error');
    expect($record['message'])->toBe('[payments] [DATABASE TRANSACTION RETRY - FAILED] Illuminate\Database\QueryException (SQLSTATE 40001, Driver 1213) After (Attempts: 3/3) - Error');
    expect($record['context']['attempt'])->toBe(3);
    expect($record['context']['maxRetries'])->toBe(3);
    expect($record['context']['trxLabel'])->toBe('payments');
    expect($record['context']['exceptionClass'])->toBe(QueryException::class);
    expect($record['context']['sqlState'])->toBe('40001');
    expect($record['context']['driverCode'])->toBe(1213);
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
    expect($this->logManager->records)->toBe([]);
    expect(SleepSpy::$delays)->toBe([]);
});

test('retries when driver code is configured', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.retryable_exceptions.driver_error_codes',
        [1213, 999]
    );

    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeQueryException(999, 0);
        }

        return 'recovered';
    }, maxRetries: 3, retryDelay: 1, trxLabel: 'invoices');

    expect($result)->toBe('recovered');
    expect($this->database->transactionCalls)->toBe(2);
    expect($this->logManager->records)->toHaveCount(1);
    $record = $this->logManager->records[0];

    expect($record['message'])->toBe('[invoices] [DATABASE TRANSACTION RETRY - SUCCESS] Illuminate\Database\QueryException (SQLSTATE 00000, Driver 999) After (Attempts: 1/3) - Warning');
    expect($record['context']['driverCode'])->toBe(999);
    expect($record['context']['sqlState'])->toBe('00000');
});

test('retries when exception class is configured', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.retryable_exceptions.classes',
        [CustomRetryException::class]
    );

    $attempts = 0;

    $result = TransactionRetrier::runWithRetry(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new CustomRetryException('try again');
        }

        return 'ok';
    }, maxRetries: 3, retryDelay: 1, trxLabel: 'custom');

    expect($result)->toBe('ok');
    expect($this->database->transactionCalls)->toBe(2);

    $record = $this->logManager->records[0];

    expect($record['message'])->toBe('[custom] [DATABASE TRANSACTION RETRY - SUCCESS] Tests\\CustomRetryException After (Attempts: 1/3) - Warning');
    expect($record['context']['exceptionClass'])->toBe(CustomRetryException::class);
    expect(array_key_exists('driverCode', $record['context']))->toBeFalse();
    expect(array_key_exists('sqlState', $record['context']))->toBeFalse();
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

final class CustomRetryException extends \RuntimeException
{
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

final class FakeLogger extends AbstractLogger
{
    public function __construct(private FakeLogManager $manager)
    {
    }

    public function log($level, $message, array $context = []): void
    {
        $this->manager->records[] = [
            'level'   => strtolower((string) $level),
            'message' => (string) $message,
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

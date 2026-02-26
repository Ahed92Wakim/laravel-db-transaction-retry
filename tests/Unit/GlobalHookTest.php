<?php

namespace DatabaseTransactions\RetryHelper;

if (! function_exists('DatabaseTransactions\RetryHelper\sleep')) {
    function sleep(int $seconds): void
    {
        \Tests\SleepSpy::record($seconds);
    }
}

namespace Tests;

use Closure;
use DatabaseTransactions\RetryHelper\Database\RetryableConnection;
use DatabaseTransactions\RetryHelper\Services\TransactionRetrier;
use DatabaseTransactions\RetryHelper\Support\RetryToggle;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    FakeDatabaseManager::flushMacros();

    $this->database   = new FakeDatabaseManager();
    $this->logManager = new FakeLogManager();

    $this->app->instance('db', $this->database);
    $this->app->instance('log', $this->logManager);

    Container::getInstance()->make('config')->set(
        'database-transaction-retry.logging.driver',
        'log'
    );

    Container::getInstance()->make('config')->set(
        'database-transaction-retry.global_hook',
        ['enabled' => false]
    );

    SleepSpy::reset();
    RetryToggle::enable();
    FakeRetryableConnection::resetRecursionGuard();
});

test('global hook disabled by default does not retry on deadlock', function (): void {
    $connection = new FakeRetryableConnection();

    try {
        $connection->transaction(function () {
            throw makeGlobalHookQueryException(1213);
        });

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $e) {
        expect($e->errorInfo[1])->toBe(1213);
    }

    // Only 1 attempt — no retry because global hook is disabled
    expect($connection->parentTransactionCalls)->toBe(1);
    expect(SleepSpy::$delays)->toBe([]);
});

test('global hook enabled routes transaction through retry logic', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.global_hook',
        ['enabled' => true]
    );

    $connection = new FakeRetryableConnection();
    $attempts   = 0;

    $result = $connection->transaction(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw makeGlobalHookQueryException(1213);
        }

        return 'recovered';
    });

    expect($result)->toBe('recovered');
    // TransactionRetrier calls DB::transaction() internally (via the fake db manager)
    expect($this->database->transactionCalls)->toBeGreaterThanOrEqual(2);
    expect(SleepSpy::$delays)->toHaveCount(1);
});

test('global hook does not cause infinite recursion', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.global_hook',
        ['enabled' => true]
    );

    $connection = new FakeRetryableConnection();

    $result = $connection->transaction(fn () => 'no-recursion');

    expect($result)->toBe('no-recursion');
});

test('global hook retries and eventually succeeds after deadlock', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.global_hook',
        ['enabled' => true]
    );

    $connection = new FakeRetryableConnection();
    $attempts   = 0;

    $result = $connection->transaction(function () use (&$attempts) {
        $attempts++;

        if ($attempts <= 2) {
            throw makeGlobalHookQueryException(1213);
        }

        return 'success-after-retries';
    });

    expect($result)->toBe('success-after-retries');
    expect($attempts)->toBe(3);
    expect(SleepSpy::$delays)->toHaveCount(2);
});

test('global hook throws after max retries exhausted', function (): void {
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.global_hook',
        ['enabled' => true]
    );

    Container::getInstance()->make('config')->set(
        'database-transaction-retry.max_retries',
        2
    );

    $connection = new FakeRetryableConnection();

    try {
        $connection->transaction(function () {
            throw makeGlobalHookQueryException(1213);
        });

        $this->fail('Expected QueryException was not thrown.');
    } catch (QueryException $e) {
        expect($e->errorInfo[1])->toBe(1213);
    }

    expect(SleepSpy::$delays)->toHaveCount(1);
});

test('global hook can be toggled at runtime', function (): void {
    $connection = new FakeRetryableConnection();

    // Start disabled — should not retry
    $attempts = 0;
    try {
        $connection->transaction(function () use (&$attempts) {
            $attempts++;
            throw makeGlobalHookQueryException(1213);
        });
    } catch (QueryException) {
    }
    expect($attempts)->toBe(1);

    // Enable at runtime
    Container::getInstance()->make('config')->set(
        'database-transaction-retry.global_hook',
        ['enabled' => true]
    );

    $attempts = 0;
    SleepSpy::reset();

    $result = $connection->transaction(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw makeGlobalHookQueryException(1213);
        }
        return 'retried';
    });

    expect($result)->toBe('retried');
    expect($attempts)->toBe(2);
    expect(SleepSpy::$delays)->toHaveCount(1);
});

function makeGlobalHookQueryException(int $driverCode, string|int $sqlState = 40001): QueryException
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

/**
 * A fake connection class that provides a parent transaction() method
 * for the RetryableConnection trait to call via parent::.
 */
class FakeBaseConnection
{
    public int $parentTransactionCalls = 0;

    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->parentTransactionCalls++;

        return $callback();
    }
}

/**
 * Uses the RetryableConnection trait with FakeBaseConnection as the parent,
 * mimicking how RetryableMySqlConnection extends MySqlConnection.
 */
final class FakeRetryableConnection extends FakeBaseConnection
{
    use RetryableConnection;

    /**
     * Reset the static recursion guard between tests.
     */
    public static function resetRecursionGuard(): void
    {
        // Access the trait's private static property via reflection
        $reflection = new \ReflectionClass(self::class);
        $property   = $reflection->getProperty('insideRetry');
        $property->setValue(null, false);
    }
}

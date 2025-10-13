<?php

declare(strict_types=1);

namespace MysqlDeadlocks\RetryHelper;

function sleep(int $seconds): void
{
    \Tests\SleepSpy::record($seconds);
}

namespace Tests;

use Illuminate\Database\QueryException;
use MysqlDeadlocks\RetryHelper\DBTransactionRetryHelper;

final class DBTransactionRetryHelperTest extends TestCase
{
    private FakeDatabaseManager $database;
    private FakeLogManager $logManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database  = new FakeDatabaseManager();
        $this->logManager = new FakeLogManager();

        $this->app->instance('db', $this->database);
        $this->app->instance('log', $this->logManager);

        SleepSpy::reset();
    }

    public function testReturnsCallbackResultWithoutRetries(): void
    {
        $result = DBTransactionRetryHelper::transactionWithRetry(fn () => 'done');

        self::assertSame('done', $result);
        self::assertSame(1, $this->database->transactionCalls);
        self::assertEmpty($this->logManager->records);
        self::assertSame([], SleepSpy::$delays);
    }

    public function testRetriesOnDeadlockAndLogsWarning(): void
    {
        $attempts = 0;

        $result = DBTransactionRetryHelper::transactionWithRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->makeQueryException(1213);
            }

            return 'recovered';
        }, maxRetries: 3, retryDelay: 1, trxLabel: 'orders');

        self::assertSame('recovered', $result);
        self::assertSame(2, $this->database->transactionCalls);
        self::assertCount(1, SleepSpy::$delays);
        self::assertSame(1, SleepSpy::$delays[0]);

        self::assertCount(1, $this->logManager->records);
        $record = $this->logManager->records[0];
        self::assertSame('warning', $record['level']);
        self::assertSame('[orders] [MYSQL DEADLOCK RETRY - SUCCESS] After (Attempts: 1/3) - Warning', $record['message']);
        self::assertSame(1, $record['context']['attempt']);
        self::assertSame(3, $record['context']['maxRetries']);
        self::assertSame('orders', $record['context']['trxLabel']);
    }

    public function testThrowsAfterMaxRetriesAndLogsError(): void
    {
        try {
            DBTransactionRetryHelper::transactionWithRetry(function () {
                throw $this->makeQueryException(1213);
            }, maxRetries: 3, retryDelay: 1, trxLabel: 'payments');
            self::fail('Expected QueryException was not thrown.');
        } catch (QueryException $th) {
            self::assertSame(1213, $th->errorInfo[1]);
        } finally {
            self::assertSame(3, $this->database->transactionCalls);
            self::assertCount(2, SleepSpy::$delays);
            self::assertSame(1, SleepSpy::$delays[0]);
            self::assertGreaterThanOrEqual(1, SleepSpy::$delays[1]);
            self::assertLessThanOrEqual(3, SleepSpy::$delays[1]);

            self::assertCount(1, $this->logManager->records);
            $record = $this->logManager->records[0];
            self::assertSame('error', $record['level']);
            self::assertSame('[payments] [MYSQL DEADLOCK RETRY - FAILED] After (Attempts: 3/3) - Error', $record['message']);
            self::assertSame(3, $record['context']['attempt']);
            self::assertSame(3, $record['context']['maxRetries']);
            self::assertSame('payments', $record['context']['trxLabel']);
        }
    }

    public function testDoesNotRetryForNonDeadlockQueryException(): void
    {
        try {
            DBTransactionRetryHelper::transactionWithRetry(function () {
                throw $this->makeQueryException(999, 0);
            }, maxRetries: 3, retryDelay: 1);
            self::fail('Expected QueryException was not thrown.');
        } catch (QueryException $th) {
            self::assertSame(999, $th->errorInfo[1]);
        }

        self::assertSame(1, $this->database->transactionCalls);
        self::assertEmpty($this->logManager->records);
        self::assertSame([], SleepSpy::$delays);
    }

    private function makeQueryException(int $driverCode, int $sqlState = 40001): QueryException
    {
        $sqlStateString = str_pad((string) $sqlState, 5, '0', STR_PAD_LEFT);
        $pdo = new \PDOException('SQLSTATE[' . $sqlStateString . ']: Driver error', $sqlState);
        $pdo->errorInfo = [$sqlStateString, $driverCode, 'Driver error'];

        return new QueryException(
            'mysql',
            'insert into foo (bar) values (?)',
            ['baz'],
            $pdo
        );
    }
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

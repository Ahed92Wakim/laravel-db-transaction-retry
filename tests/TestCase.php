<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected TestApplication $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new TestApplication();

        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();

        $configRepository = new Repository();
        $configRepository->set(
            'database-transaction-retry',
            require dirname(__DIR__) . '/config/database-transaction-retry.php'
        );

        $this->app->instance('config', $configRepository);
        $this->app->instance('path.storage', $this->app->storagePath());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }
}

final class TestApplication extends Container
{
    public function storagePath($path = ''): string
    {
        $base = sys_get_temp_dir() . '/laravel-db-transaction-retry/storage';

        return $path === '' ? $base : $base . '/' . ltrim((string) $path, '/');
    }

    public function runningUnitTests(): bool
    {
        return true;
    }
}

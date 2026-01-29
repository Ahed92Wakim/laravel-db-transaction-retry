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
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = sys_get_temp_dir() . '/laravel-db-transaction-retry';
    }

    public function setBasePath(string $path): void
    {
        $this->basePath = rtrim($path, '/');
    }

    public function basePath($path = ''): string
    {
        return $path === '' ? $this->basePath : $this->basePath . '/' . ltrim((string) $path, '/');
    }

    public function path($path = ''): string
    {
        return $this->basePath('app' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function configPath($path = ''): string
    {
        return $this->basePath('config' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function databasePath($path = ''): string
    {
        return $this->basePath('database' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function publicPath($path = ''): string
    {
        return $this->basePath('public' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function storagePath($path = ''): string
    {
        return $this->basePath('storage' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function runningInConsole(): bool
    {
        return false;
    }

    public function routesAreCached(): bool
    {
        return true;
    }

    public function runningUnitTests(): bool
    {
        return true;
    }
}

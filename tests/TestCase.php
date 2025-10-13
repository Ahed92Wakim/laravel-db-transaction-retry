<?php

declare(strict_types=1);

namespace Tests;

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

        $this->app->instance('config', []);
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
        $base = sys_get_temp_dir() . '/laravel-mysql-deadlock-retry/storage';

        return $path === '' ? $base : $base . '/' . ltrim((string) $path, '/');
    }
}

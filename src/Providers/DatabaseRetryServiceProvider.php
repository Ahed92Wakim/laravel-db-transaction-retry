<?php

namespace MysqlDeadlocks\RetryHelper\Providers;

use Illuminate\Support\ServiceProvider;

class DatabaseRetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register bindings or aliases related to retry helpers here if needed.
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Hook for publishing configuration or adding macros in future versions.
    }
}

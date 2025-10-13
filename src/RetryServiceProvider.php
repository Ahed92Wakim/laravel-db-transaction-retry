<?php

namespace MysqlDeadlocks\RetryHelper;

use Illuminate\Support\ServiceProvider;

class RetryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register bindings or helpers here (if needed in the future)
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // You can publish config files or perform other setup tasks here
    }
}

<?php

namespace MysqlDeadlocks\RetryHelper;

use Illuminate\Support\ServiceProvider;

class RetryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register bindings or helpers here (if needed in the future)
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // You can publish config files or perform other setup tasks here
    }
}
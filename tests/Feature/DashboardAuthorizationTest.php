<?php

use DatabaseTransactions\RetryHelper\TransactionRetryDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    TransactionRetryDashboard::resetAuth();
});

it('allows access in local environment by default', function (): void {
    $this->app->detectEnvironment(function () {
        return 'local';
    });

    $request = Request::create('/transaction-retry/transactions');

    expect(TransactionRetryDashboard::check($request))->toBeTrue();
});

it('denies access in production environment by default', function (): void {
    $this->app->detectEnvironment(function () {
        return 'production';
    });

    $request = Request::create('/transaction-retry/transactions');

    expect(TransactionRetryDashboard::check($request))->toBeFalse();
});

it('allows access if gate allows it', function (): void {
    $this->app->detectEnvironment(function () {
        return 'production';
    });

    Gate::define('viewTransactionRetryDashboard', function ($user) {
        return true;
    });

    $user    = new \Illuminate\Foundation\Auth\User();
    $request = Request::create('/transaction-retry/transactions');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    expect(TransactionRetryDashboard::check($request))->toBeTrue();
});

it('denies access if gate denies it', function (): void {
    $this->app->detectEnvironment(function () {
        return 'production';
    });

    Gate::define('viewTransactionRetryDashboard', function ($user) {
        return false;
    });

    $user    = new \Illuminate\Foundation\Auth\User();
    $request = Request::create('/transaction-retry/transactions');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    expect(TransactionRetryDashboard::check($request))->toBeFalse();
});

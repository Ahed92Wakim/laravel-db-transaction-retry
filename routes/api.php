<?php

use DatabaseTransactions\RetryHelper\Http\Controllers\TransactionRetryEventController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('database-transaction-retry.api.middleware', []))
    ->prefix(trim((string) config('database-transaction-retry.api.prefix', 'api/transaction-retry'), '/'))
    ->group(function (): void {
        Route::get('events', [TransactionRetryEventController::class, 'index']);
        Route::get('events/{id}', [TransactionRetryEventController::class, 'show'])->whereNumber('id');
    });

<?php

use App\Http\Controllers\ExportTransactionsController;
use App\Http\Controllers\OfflineTransactionController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ShortcutController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');

    Route::livewire('transactions', 'pages::transactions.index')->name('transactions.index');
    Route::get('transactions/export', ExportTransactionsController::class)->name('transactions.export');
    Route::post('transactions/offline', OfflineTransactionController::class)->name('transactions.offline');

    Route::livewire('upcoming', 'pages::upcoming.index')->name('upcoming.index');

    Route::livewire('debts', 'pages::debts.index')->name('debts.index');
    Route::livewire('loans', 'pages::debts.index')->defaults('kind', 'loan')->name('loans.index');
    Route::livewire('loans/create', 'pages::debts.form')->defaults('kind', 'loan')->name('loans.create');
    Route::livewire('loans/{debt}', 'pages::debts.show')->name('loans.show');
    Route::livewire('loans/{debt}/edit', 'pages::debts.form')->name('loans.edit');
    Route::livewire('debts/create', 'pages::debts.form')->name('debts.create');
    Route::livewire('debts/{debt}', 'pages::debts.show')->name('debts.show');
    Route::livewire('debts/{debt}/edit', 'pages::debts.form')->name('debts.edit');

    Route::livewire('recurring', 'pages::recurring.index')->name('recurring.index');

    Route::livewire('categories', 'pages::categories.index')->name('categories.index');

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
});

Route::prefix('shortcut')
    ->name('shortcut.')
    ->middleware('throttle:60,1')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->group(function () {
        Route::get('categories', [ShortcutController::class, 'categories'])->name('categories');
        Route::post('transactions', [ShortcutController::class, 'store'])->name('transactions.store');
    });

require __DIR__.'/settings.php';

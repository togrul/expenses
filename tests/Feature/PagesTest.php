<?php

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;

test('authenticated users can open every page', function (string $route) {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();
    DebtPayment::factory()->for($debt)->dueOn(now()->addDays(2)->toDateString())->create();
    RecurringPayment::factory()->for($user)->create();
    Transaction::factory()->for($user)->count(3)->create();

    $this->actingAs($user)
        ->get(route($route, $route === 'debts.show' || $route === 'debts.edit' ? $debt : []))
        ->assertOk();
})->with([
    'dashboard', 'transactions.index', 'upcoming.index', 'debts.index', 'debts.create', 'loans.index', 'loans.create',
    'debts.show', 'debts.edit', 'recurring.index', 'categories.index', 'finance.edit',
]);

test('users cannot open another users debt', function (string $route) {
    $debt = Debt::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route($route, $debt))
        ->assertForbidden();
})->with(['debts.show', 'debts.edit']);

test('new users get the default categories', function () {
    $user = User::factory()->create();

    expect($user->categories()->count())->toBe(16)
        ->and($user->categories()->where('system_key', 'debt_payment')->exists())->toBeTrue();
});

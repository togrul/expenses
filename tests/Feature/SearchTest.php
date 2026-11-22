<?php

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('debts can be searched by counterparty, title or note case-insensitively', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['counterparty' => 'Kapital Bank', 'title' => null]);
    Debt::factory()->for($user)->create(['counterparty' => 'Elvin', 'title' => 'Telefon']);
    Debt::factory()->create(['counterparty' => 'Kapital Bank']);

    Livewire::actingAs($user)
        ->test('pages::debts.index')
        ->set('search', 'kapital')
        ->assertSee('Kapital Bank')
        ->assertDontSee('Elvin')
        ->set('search', 'telefon')
        ->assertSee('Elvin')
        ->assertDontSee('Kapital Bank');
});

test('transactions can be searched by category name', function () {
    $user = User::factory()->create();
    $food = $user->categories()->where('name', 'Qida')->sole();
    Transaction::factory()->for($user)->create(['category_id' => $food->id, 'note' => 'bazar', 'occurred_on' => now()]);
    Transaction::factory()->for($user)->create(['category_id' => null, 'note' => 'taksi', 'occurred_on' => now()]);

    Livewire::actingAs($user)
        ->test('pages::transactions.index')
        ->set('search', 'qida')
        ->assertSee('bazar')
        ->assertDontSee('taksi');
});

test('recurring payments and upcoming items can be searched', function () {
    $user = User::factory()->create();
    RecurringPayment::factory()->for($user)->create(['name' => 'Netflix']);
    RecurringPayment::factory()->for($user)->create(['name' => 'Mənzil kirayəsi']);
    DebtPayment::factory()->for(Debt::factory()->for($user)->state(['counterparty' => 'Elvin']))->dueOn(now()->addDay()->toDateString())->create();

    Livewire::actingAs($user)
        ->test('pages::recurring.index')
        ->set('search', 'netf')
        ->assertSee('Netflix')
        ->assertDontSee('Mənzil kirayəsi');

    Livewire::actingAs($user)
        ->test('upcoming-list', ['searchable' => true])
        ->set('search', 'elvin')
        ->assertSee('Elvin')
        ->assertDontSee('Netflix');
});

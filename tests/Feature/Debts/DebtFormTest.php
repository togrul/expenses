<?php

use App\Models\Debt;
use App\Models\User;
use Livewire\Livewire;

test('creating a debt with the generator creates equal installments', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::debts.form')
        ->set('counterparty', 'Kapital Bank')
        ->set('totalAmount', '1000')
        ->set('generateSchedule', true)
        ->set('installments', 3)
        ->set('firstDueOn', '2026-10-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $debt = Debt::sole();

    expect($debt)
        ->user_id->toBe($user->id)
        ->total_amount->toBe(100000)
        ->and($debt->sortedPayments()->map(fn ($p) => [$p->due_on->toDateString(), $p->amount])->all())
        ->toBe([['2026-10-31', 33333], ['2026-11-30', 33333], ['2026-12-31', 33334]]);
});

test('the total cannot be lowered below the amount already paid', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['total_amount' => 50000]);
    $debt->payments()->create(['amount' => 30000, 'paid_at' => now(), 'paid_amount' => 30000]);

    Livewire::actingAs($user)
        ->test('pages::debts.form', ['debt' => $debt])
        ->set('totalAmount', '200')
        ->call('save')
        ->assertHasErrors('total_amount');
});

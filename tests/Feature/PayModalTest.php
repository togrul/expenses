<?php

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('paying an installment from the modal writes the expense', function () {
    $user = User::factory()->create();
    $payment = DebtPayment::factory()->for(Debt::factory()->for($user)->state(['total_amount' => 30000]))->create(['amount' => 15000]);

    Livewire::actingAs($user)
        ->test('pay-modal')
        ->call('openPay', 'debt_payment', $payment->id)
        ->assertSet('amount', '150,00')
        ->set('paidOn', '2026-09-21')
        ->call('pay')
        ->assertHasNoErrors()
        ->assertDispatched('payment-updated');

    expect($payment->fresh()->paid_amount)->toBe(15000)
        ->and(Transaction::sole()->amount)->toBe(15000);
});

test('variable occurrences require the actual amount', function () {
    $user = User::factory()->create();
    $occurrence = RecurringOccurrence::factory()
        ->for(RecurringPayment::factory()->for($user)->state(['is_variable' => true]))
        ->create();

    Livewire::actingAs($user)
        ->test('pay-modal')
        ->call('openPay', 'occurrence', $occurrence->id)
        ->assertSet('amount', '')
        ->call('pay')
        ->assertHasErrors('amount');
});

test('undo from the modal reverts the payment', function () {
    $user = User::factory()->create();
    $payment = DebtPayment::factory()->for(Debt::factory()->for($user))->create(['amount' => 15000]);

    $component = Livewire::actingAs($user)->test('pay-modal')
        ->call('openPay', 'debt_payment', $payment->id)
        ->call('pay');

    $component->call('openUndo', 'debt_payment', $payment->id)->call('undo');

    expect($payment->fresh()->isPaid())->toBeFalse()
        ->and(Transaction::withTrashed()->count())->toBe(0);
});

test('users cannot pay another users installment or occurrence', function (string $kind) {
    $id = $kind === 'debt_payment'
        ? DebtPayment::factory()->create()->id
        : RecurringOccurrence::factory()->create()->id;

    Livewire::actingAs(User::factory()->create())
        ->test('pay-modal')
        ->call('openPay', $kind, $id)
        ->assertForbidden();
})->with(['debt_payment', 'occurrence']);

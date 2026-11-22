<?php

use App\Actions\Debts\PayDebtPayment;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('the quick add modal creates a transaction from a human amount', function () {
    $user = User::factory()->create();
    $category = $user->categories()->where('name', 'Qida')->sole();

    Livewire::actingAs($user)
        ->test('transaction-modal')
        ->call('create')
        ->set('amount', '1 234,50')
        ->set('categoryId', $category->id)
        ->set('occurredOn', '2026-09-21')
        ->set('note', 'Bazar')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    expect(Transaction::sole())
        ->user_id->toBe($user->id)
        ->amount->toBe(123450)
        ->category_id->toBe($category->id)
        ->type->value->toBe('expense');
});

test('the modal rejects invalid amounts and foreign categories', function () {
    $user = User::factory()->create();
    $foreignCategory = Category::factory()->create();

    Livewire::actingAs($user)
        ->test('transaction-modal')
        ->call('create')
        ->set('amount', '-5')
        ->call('save')
        ->assertHasErrors('amount')
        ->set('amount', '10')
        ->set('categoryId', $foreignCategory->id)
        ->call('save')
        ->assertHasErrors('category_id');

    expect(Transaction::count())->toBe(0);
});

test('users cannot edit another users transaction', function () {
    $transaction = Transaction::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('transaction-modal')
        ->call('edit', $transaction->id);
})->throws(ModelNotFoundException::class);

test('linked transactions cannot be deleted from the list', function () {
    $user = User::factory()->create();
    $payment = DebtPayment::factory()->for(Debt::factory()->for($user))->create(['amount' => 5000]);
    app(PayDebtPayment::class)->handle($payment, 5000, CarbonImmutable::today());
    $transaction = Transaction::sole();

    Livewire::actingAs($user)
        ->test('pages::transactions.index')
        ->call('confirmDelete', $transaction->id)
        ->call('delete');

    expect($transaction->fresh()->trashed())->toBeFalse();
});

test('the list summary sums only the filtered month', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->income()->create(['amount' => 100000, 'occurred_on' => '2026-09-05']);
    Transaction::factory()->for($user)->expense()->create(['amount' => 25050, 'occurred_on' => '2026-09-30']);
    Transaction::factory()->for($user)->expense()->create(['amount' => 99999, 'occurred_on' => '2026-08-31']);
    Transaction::factory()->expense()->create(['amount' => 77777, 'occurred_on' => '2026-09-10']);

    Livewire::actingAs($user)
        ->test('pages::transactions.index', ['month' => '2026-09'])
        ->set('month', '2026-09')
        ->assertSet('summary', ['income' => 100000, 'expense' => 25050, 'net' => 74950]);
});

test('csv export only contains the users own filtered transactions', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->expense()->create(['amount' => 1250, 'occurred_on' => '2026-09-05', 'note' => 'mine']);
    Transaction::factory()->expense()->create(['amount' => 999, 'occurred_on' => '2026-09-05', 'note' => 'foreign']);

    $response = $this->actingAs($user)->get(route('transactions.export', ['month' => '2026-09']));

    $response->assertOk();
    expect($response->streamedContent())->toContain('mine')->toContain('-12,50')->not->toContain('foreign');
});

<?php

use App\Actions\Debts\AddAdHocDebtPayment;
use App\Actions\Debts\GenerateDebtSchedule;
use App\Actions\Debts\PayDebtPayment;
use App\Actions\Debts\UndoDebtPayment;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

test('full payment creates a linked expense and closes a fully paid debt', function () {
    $debt = Debt::factory()->create(['total_amount' => 15000, 'counterparty' => 'Elvin']);
    $payment = DebtPayment::factory()->for($debt)->create(['amount' => 15000]);

    app(PayDebtPayment::class)->handle($payment, 15000, CarbonImmutable::parse('2026-09-10'));

    $transaction = Transaction::sole();

    expect($payment->fresh())
        ->paid_at->toDateString()->toBe('2026-09-10')
        ->paid_amount->toBe(15000)
        ->transaction_id->toBe($transaction->id)
        ->and($transaction)
        ->type->toBe(TransactionType::Expense)
        ->amount->toBe(15000)
        ->user_id->toBe($debt->user_id)
        ->source_type->toBe('debt_payment')
        ->source_id->toBe($payment->id)
        ->note->toContain('Elvin')
        ->and($transaction->category->system_key)->toBe(Category::DebtPaymentKey)
        ->and($debt->fresh()->closed_at)->not->toBeNull();
});

test('partial payment creates a new unpaid installment for the difference', function () {
    $debt = Debt::factory()->create(['total_amount' => 30000]);
    $payment = DebtPayment::factory()->for($debt)->dueOn('2026-10-01')->create(['amount' => 30000]);

    app(PayDebtPayment::class)->handle($payment, 10000, CarbonImmutable::parse('2026-09-21'));

    $remainder = DebtPayment::query()->whereNull('paid_at')->sole();

    expect($remainder)
        ->amount->toBe(20000)
        ->due_on->toDateString()->toBe('2026-10-01')
        ->split_from_id->toBe($payment->id)
        ->and($debt->fresh()->remaining())->toBe(20000)
        ->and($debt->fresh()->closed_at)->toBeNull();
});

test('payment can skip writing an expense transaction', function () {
    $payment = DebtPayment::factory()->create(['amount' => 5000]);

    app(PayDebtPayment::class)->handle($payment, 5000, CarbonImmutable::today(), createTransaction: false);

    expect(Transaction::count())->toBe(0)
        ->and($payment->fresh()->isPaid())->toBeTrue();
});

test('payment larger than the remaining balance is rejected', function () {
    $debt = Debt::factory()->create(['total_amount' => 10000]);
    $payment = DebtPayment::factory()->for($debt)->create(['amount' => 10000]);

    app(PayDebtPayment::class)->handle($payment, 10001, CarbonImmutable::today());
})->throws(ValidationException::class);

test('undo deletes the transaction, merges the remainder back and reopens the debt', function () {
    $debt = Debt::factory()->create(['total_amount' => 30000]);
    $payment = DebtPayment::factory()->for($debt)->create(['amount' => 30000]);

    app(PayDebtPayment::class)->handle($payment, 10000, CarbonImmutable::today());
    app(UndoDebtPayment::class)->handle($payment->fresh());

    expect(Transaction::withTrashed()->count())->toBe(0)
        ->and(DebtPayment::count())->toBe(1)
        ->and($payment->fresh())->paid_at->toBeNull()->paid_amount->toBeNull()->transaction_id->toBeNull()
        ->and($debt->fresh()->remaining())->toBe(30000);
});

test('undo reopens a closed debt', function () {
    $debt = Debt::factory()->create(['total_amount' => 10000]);
    $payment = DebtPayment::factory()->for($debt)->create(['amount' => 10000]);

    app(PayDebtPayment::class)->handle($payment, 10000, CarbonImmutable::today());
    expect($debt->fresh()->closed_at)->not->toBeNull();

    app(UndoDebtPayment::class)->handle($payment->fresh());

    expect($debt->fresh()->closed_at)->toBeNull();
});

test('schedule splits the balance evenly with the remainder qəpik on the last installment', function () {
    $debt = Debt::factory()->create(['total_amount' => 100000]);

    $payments = app(GenerateDebtSchedule::class)->handle($debt, 3, CarbonImmutable::parse('2026-01-31'));

    expect($payments->pluck('amount')->all())->toBe([33333, 33333, 33334])
        ->and($payments->map(fn (DebtPayment $p) => $p->due_on->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

test('schedule replaces unpaid installments and only covers the remaining balance', function () {
    $debt = Debt::factory()->create(['total_amount' => 100000]);
    $paid = DebtPayment::factory()->for($debt)->create(['amount' => 40000]);
    DebtPayment::factory()->for($debt)->create(['amount' => 60000]);
    app(PayDebtPayment::class)->handle($paid, 40000, CarbonImmutable::today());

    $payments = app(GenerateDebtSchedule::class)->handle($debt->fresh(), 2);

    expect($payments->pluck('amount')->all())->toBe([30000, 30000])
        ->and($payments->pluck('due_on')->filter())->toBeEmpty()
        ->and($debt->payments()->whereNull('paid_at')->count())->toBe(2);
});

test('ad hoc payment on a debt without installments creates a paid installment', function () {
    $debt = Debt::factory()->create(['total_amount' => 50000]);

    $payment = app(AddAdHocDebtPayment::class)->handle($debt, 20000, CarbonImmutable::parse('2026-09-21'));

    expect($payment)
        ->paid_amount->toBe(20000)
        ->due_on->toDateString()->toBe('2026-09-21')
        ->and($debt->fresh()->remaining())->toBe(30000)
        ->and(Transaction::sole()->amount)->toBe(20000);
});

test('debt status reflects overdue unpaid installments', function () {
    $this->travelTo('2026-09-21');
    $debt = Debt::factory()->create(['total_amount' => 20000]);
    DebtPayment::factory()->for($debt)->dueOn('2026-09-20')->create(['amount' => 10000]);

    expect($debt->fresh()->status()->value)->toBe('overdue');
});

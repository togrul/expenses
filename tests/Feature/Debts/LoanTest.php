<?php

use App\Actions\Debts\CreateLoan;
use App\Actions\Debts\PayDebtPayment;
use App\Actions\Debts\RecordDebtPaymentExpense;
use App\Actions\Debts\UndoDebtPayment;
use App\Actions\Debts\UpdateDebtPayment;
use App\Actions\Debts\UpdateLoan;
use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use App\Support\LoanCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function createLoan(User $user, array $overrides = []): Debt
{
    return app(CreateLoan::class)->handle($user, [
        'counterparty' => 'Kapital Bank',
        'principal' => 1_000_000,
        'interest_rate' => 12.0,
        'term_months' => 12,
        'schedule_type' => 'annuity',
        'first_due_on' => '2026-01-31',
        ...$overrides,
    ]);
}

test('creating a loan builds the amortization schedule and total payable', function () {
    $debt = createLoan(User::factory()->create());

    $payments = $debt->sortedPayments();

    expect($payments)->toHaveCount(12)
        ->and($payments->first())->amount->toBe(88849)->interest_amount->toBe(10000)->installment_number->toBe(1)
        ->and($payments->pluck('due_on')->map->toDateString()->take(3)->all())->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and($debt->fresh()->total_amount)->toBe($payments->sum('amount'))
        ->and($debt->fresh()->due_on->toDateString())->toBe('2026-12-31')
        ->and($debt->loanSummary())->toMatchArray(['principal' => 1_000_000, 'principal_left' => 1_000_000, 'paid_count' => 0]);
});

test('paying an installment moves principal and interest into the paid totals', function () {
    $debt = createLoan(User::factory()->create());
    $first = $debt->sortedPayments()->first();

    app(PayDebtPayment::class)->handle($first, $first->amount, CarbonImmutable::parse('2026-01-31'));

    expect($debt->fresh()->loanSummary())->toMatchArray([
        'principal_paid' => 78849, 'interest_paid' => 10000, 'principal_left' => 921151, 'paid_count' => 1,
    ]);
});

test('a partial loan payment covers interest first and undo restores the installment', function () {
    $debt = createLoan(User::factory()->create());
    $first = $debt->sortedPayments()->first();

    app(PayDebtPayment::class)->handle($first, 30000, CarbonImmutable::today());

    $remainder = $debt->payments()->whereNotNull('split_from_id')->sole();
    expect($first->fresh())->interest_amount->toBe(10000)->principal_amount->toBe(20000)
        ->and($remainder)->interest_amount->toBe(0)->principal_amount->toBe(58849)->amount->toBe(58849);

    app(UndoDebtPayment::class)->handle($first->fresh());

    expect($first->fresh())->interest_amount->toBe(10000)->principal_amount->toBe(78849)
        ->and($debt->payments()->count())->toBe(12);
});

test('loan terms cannot change after a payment but can before', function () {
    $user = User::factory()->create();
    $debt = createLoan($user);
    $data = ['counterparty' => 'Kapital Bank', 'principal' => 600_000, 'interest_rate' => 12.0, 'term_months' => 6, 'schedule_type' => 'annuity', 'first_due_on' => '2026-01-31'];

    app(UpdateLoan::class)->handle($debt, $data);
    expect($debt->fresh()->payments()->count())->toBe(6);

    $first = $debt->fresh()->sortedPayments()->first();
    app(PayDebtPayment::class)->handle($first, $first->amount, CarbonImmutable::today());

    app(UpdateLoan::class)->handle($debt->fresh(), [...$data, 'term_months' => 3]);
})->throws(ValidationException::class);

test('the debt form creates a loan', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::debts.form')
        ->set('kind', 'loan')
        ->set('counterparty', 'ABB')
        ->set('totalAmount', '5 000')
        ->set('interestRate', '18')
        ->set('termMonths', 10)
        ->set('scheduleType', 'differential')
        ->set('firstDueOn', '2026-10-15')
        ->call('save')
        ->assertHasNoErrors();

    $debt = Debt::sole();

    expect($debt->isLoan())->toBeTrue()
        ->and($debt->principal)->toBe(500000)
        ->and($debt->payments()->count())->toBe(10)
        ->and($debt->sortedPayments()->first()->amount)->toBe(50000 + 7500);
});

test('loan pages render the schedule and the monthly checklist', function () {
    $this->travelTo('2026-01-10');
    $user = User::factory()->create();
    $debt = createLoan($user);
    $first = $debt->sortedPayments()->first();
    app(PayDebtPayment::class)->handle($first, $first->amount, CarbonImmutable::parse('2026-01-10'));

    $this->actingAs($user)->get(route('debts.show', $debt))
        ->assertOk()
        ->assertSee('1 / 12 ay')
        ->assertSee('Ödəniş qrafiki');

    $this->actingAs($user)->get(route('recurring.index'))
        ->assertOk()
        ->assertSee('Kapital Bank');

    $this->actingAs($user)->get(route('debts.index'))->assertOk()->assertSee('Kredit');
});

test('a loan entered mid-term marks the paid months and matches the bank schedule', function () {
    $this->travelTo('2026-09-21');

    $debt = app(CreateLoan::class)->handle(User::factory()->create(), [
        'counterparty' => 'Bank', 'principal' => 4_000_000, 'interest_rate' => 27.9, 'term_months' => 59,
        'schedule_type' => 'annuity', 'first_due_on' => '2026-07-25', 'started_on' => '2026-06-25',
        'paid_months' => 2,
    ]);

    $summary = $debt->loanSummary();

    expect($debt->sortedPayments()->first()->amount)->toBe(125282)
        ->and($summary['paid_count'])->toBe(2)
        ->and($summary['principal_left'])->toBe(3_934_685)
        ->and(Transaction::count())->toBe(0)
        ->and($summary['payoff'])->toBe(3_934_685 + LoanCalculator::accruedInterest(3_934_685, 27.9, CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-09-21')));
});

test('a fixed bank payment and daily interest drive the schedule', function () {
    $rows = LoanCalculator::schedule(
        1_000_000, 36.5, 3, LoanScheduleType::Annuity, 350_000, InterestMethod::Daily,
        LoanCalculator::dueDates(CarbonImmutable::parse('2026-02-01'), 3),
        CarbonImmutable::parse('2026-01-01'),
    );

    // 31 days at 0.1%/day = 31 000 interest; the fixed payment covers the rest as principal.
    expect($rows[0])->toMatchArray(['interest' => 31000, 'principal' => 319000, 'payment' => 350000])
        ->and(end($rows)['balance'])->toBe(0);
});

test('editing a loan installment splits interest and updates the loan total', function () {
    $debt = createLoan(User::factory()->create());
    $payment = $debt->sortedPayments()->first();

    app(UpdateDebtPayment::class)->handle($payment, ['amount' => 90000, 'principal_amount' => 79000, 'due_on' => '2026-02-02']);

    expect($payment->fresh())->interest_amount->toBe(11000)->principal_amount->toBe(79000)
        ->and($debt->fresh()->total_amount)->toBe((int) $debt->payments()->sum('amount'));
});

test('clearing numeric loan fields while typing does not break the form', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::debts.form')
        ->set('kind', 'loan')
        ->set('counterparty', 'Bank')
        ->set('totalAmount', '40000')
        ->set('interestRate', '27,9')
        ->set('paidMonths', '')
        ->set('termMonths', '')
        ->assertOk()
        ->set('termMonths', '59')
        ->set('paidMonths', '2')
        ->set('firstDueOn', '2026-07-25')
        ->call('save')
        ->assertHasNoErrors();

    expect(Debt::sole()->loanSummary()['paid_count'])->toBe(2);
});

test('personal debts and bank loans are listed in separate sections', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['counterparty' => 'Elvin']);
    $loan = createLoan($user, ['counterparty' => 'Kapital Bank']);

    $this->actingAs($user)->get(route('debts.index'))->assertOk()->assertSee('Elvin')->assertDontSee('Kapital Bank');
    $this->actingAs($user)->get(route('loans.index'))->assertOk()->assertSee('Kapital Bank')->assertDontSee('Elvin');

    expect($loan->url())->toBe(route('loans.show', $loan));
    $this->actingAs($user)->get($loan->url())->assertOk();
});

test('the loan create page starts in loan mode', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('loans.create'))
        ->assertOk()
        ->assertSee('Yeni kredit');
});

test('actual/360 interest with weekend shifting reproduces the bank balance', function () {
    $this->travelTo('2026-09-21');

    $debt = app(CreateLoan::class)->handle(User::factory()->create(), [
        'counterparty' => 'Access Bank', 'principal' => 3_100_000, 'interest_rate' => 15.0, 'term_months' => 48,
        'schedule_type' => 'annuity', 'monthly_payment' => 86_700, 'interest_method' => 'daily360', 'shift_weekends' => true,
        'started_on' => '2025-02-06', 'first_due_on' => '2025-03-10', 'paid_months' => 18,
    ]);

    // The bank shows 21 523,92 ₼; the remaining gap is public holidays.
    expect($debt->loanSummary()['principal_left'])->toBe(2_152_316)
        ->and($debt->sortedPayments()->get(2)->due_on->toDateString())->toBe('2025-05-12');
});

test('editing a paid loan keeps its terms when only the note changes', function () {
    $user = User::factory()->create();
    $debt = app(CreateLoan::class)->handle($user, [
        'counterparty' => 'Bank', 'principal' => 1_000_000, 'interest_rate' => 12.0, 'term_months' => 12,
        'schedule_type' => 'annuity', 'interest_method' => 'daily360', 'shift_weekends' => true,
        'started_on' => '2026-01-01', 'first_due_on' => '2026-02-07', 'paid_months' => 1,
    ]);

    app(UpdateLoan::class)->handle($debt->fresh(), [
        'counterparty' => 'Bank', 'principal' => 1_000_000, 'interest_rate' => 12.0, 'term_months' => 12,
        'schedule_type' => 'annuity', 'interest_method' => 'daily360', 'shift_weekends' => true,
        'started_on' => '2026-01-01', 'first_due_on' => '2026-02-09', 'note' => 'yeni qeyd',
    ]);

    expect($debt->fresh()->note)->toBe('yeni qeyd');
});

test('paid installments that were not recorded can be written into expenses later', function () {
    $user = User::factory()->create();
    $debt = createLoan($user, ['paid_months' => 3]);
    expect(Transaction::count())->toBe(0);

    $this->actingAs($user)->get(route('loans.index'))->assertSee('3 ödənilmiş ay xərclərə yazılmayıb');

    Livewire::actingAs($user)
        ->test('pages::debts.show', ['debt' => $debt])
        ->call('recordExpense');

    $this->actingAs($user)->get(route('loans.index'))->assertDontSee('xərclərə yazılmayıb');

    $paid = $debt->sortedPayments()->take(3);

    expect(Transaction::orderBy('occurred_on')->get())
        ->toHaveCount(3)
        ->each(fn ($t) => $t->type->value->toBe('expense'))
        ->and(Transaction::orderBy('occurred_on')->pluck('occurred_on')->map->toDateString()->all())
        ->toBe($paid->map(fn ($p) => $p->due_on->toDateString())->all())
        ->and($debt->payments()->whereNotNull('paid_at')->whereNull('transaction_id')->count())->toBe(0);

    // Running it again creates nothing new.
    expect(app(RecordDebtPaymentExpense::class)->forDebts([$debt]))->toBe(0);
});

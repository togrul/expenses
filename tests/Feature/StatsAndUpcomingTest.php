<?php

use App\Actions\Debts\PayDebtPayment;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StatsService;
use App\Services\UpcomingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-09-17');
});

test('month totals, series and category breakdown match the transactions', function () {
    $user = User::factory()->create();
    $food = $user->categories()->where('name', 'Qida')->sole();
    Transaction::factory()->for($user)->income()->create(['amount' => 200000, 'occurred_on' => '2026-09-01']);
    Transaction::factory()->for($user)->expense()->create(['amount' => 3000, 'occurred_on' => '2026-09-02', 'category_id' => $food->id]);
    Transaction::factory()->for($user)->expense()->create(['amount' => 2000, 'occurred_on' => '2026-09-30', 'category_id' => $food->id]);
    Transaction::factory()->for($user)->expense()->create(['amount' => 1000, 'occurred_on' => '2026-08-15']);

    $stats = app(StatsService::class);

    expect($stats->monthTotals($user, CarbonImmutable::today()))->toBe(['income' => 200000, 'expense' => 5000, 'net' => 195000])
        ->and(array_slice($stats->monthlySeries($user, 2), 0, 2))->sequence(
            fn ($row) => $row->month->toBe('2026-08')->expense->toBe(1000),
            fn ($row) => $row->month->toBe('2026-09')->income->toBe(200000)->expense->toBe(5000),
        )
        ->and($stats->categoryBreakdown($user, CarbonImmutable::today()))->toBe([['name' => 'Qida', 'color' => $food->color, 'total' => 5000]]);
});

test('debt summary and overdue totals are computed from installments', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['total_amount' => 100000]);
    $paid = DebtPayment::factory()->for($debt)->create(['amount' => 25000]);
    DebtPayment::factory()->for($debt)->dueOn('2026-09-10')->create(['amount' => 25000]);
    DebtPayment::factory()->for($debt)->dueOn('2026-10-10')->create(['amount' => 50000]);
    app(PayDebtPayment::class)->handle($paid, 25000, CarbonImmutable::today());
    RecurringOccurrence::factory()->for(RecurringPayment::factory()->for($user))->create(['due_on' => '2026-09-01', 'amount' => 3000]);
    Debt::factory()->create(['total_amount' => 999999]);

    $stats = app(StatsService::class);

    expect($stats->debtSummary($user))->toMatchArray(['total' => 100000, 'paid' => 25000, 'remaining' => 75000, 'percent' => 25])
        ->and($stats->overdue($user))->toBe(['count' => 2, 'amount' => 28000]);
});

test('fixed monthly commitments convert weekly and yearly payments', function () {
    $user = User::factory()->create();
    RecurringPayment::factory()->for($user)->create(['amount' => 3000]);
    RecurringPayment::factory()->for($user)->weekly()->create(['amount' => 1200]);
    RecurringPayment::factory()->for($user)->yearly(1, 1)->create(['amount' => 12000]);
    RecurringPayment::factory()->for($user)->inactive()->create(['amount' => 50000]);

    expect(app(StatsService::class)->fixedMonthlyCommitments($user))->toBe(3000 + 5200 + 1000);
});

test('upcoming items are grouped by due date and skipped or paid ones are hidden', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['total_amount' => 100000]);
    DebtPayment::factory()->for($debt)->dueOn('2026-09-16')->create();
    DebtPayment::factory()->for($debt)->dueOn('2026-09-17')->create();
    DebtPayment::factory()->for($debt)->dueOn('2026-09-19')->create();
    DebtPayment::factory()->for($debt)->create();
    $recurring = RecurringPayment::factory()->for($user)->create();
    RecurringOccurrence::factory()->for($recurring)->create(['due_on' => '2026-09-28']);
    RecurringOccurrence::factory()->for($recurring)->create(['due_on' => '2026-10-05']);
    RecurringOccurrence::factory()->for($recurring)->create(['due_on' => '2026-09-18', 'skipped_at' => now()]);

    $groups = app(UpcomingService::class)->grouped($user);

    expect(collect($groups)->map->count()->all())->toBe([
        'overdue' => 1, 'today' => 1, 'this_week' => 1, 'this_month' => 1, 'later' => 1, 'undated' => 1,
    ]);
});

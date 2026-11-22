<?php

use App\Actions\Recurring\GenerateOccurrences;
use App\Actions\Recurring\PayOccurrence;
use App\Actions\Recurring\SkipOccurrence;
use App\Actions\Recurring\UndoOccurrencePayment;
use App\Actions\Recurring\UpdateRecurringPayment;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function dueDates(RecurringPayment $payment): array
{
    return $payment->occurrences()->orderBy('due_on')->pluck('due_on')->map(fn ($d) => $d->toDateString())->all();
}

test('monthly schedule clamps the 31st to the last day of shorter months', function () {
    $payment = RecurringPayment::factory()->create(['day_of_month' => 31, 'starts_on' => '2026-01-01']);

    app(GenerateOccurrences::class)->forPayment($payment, CarbonImmutable::parse('2026-04-30'));

    expect(dueDates($payment))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

test('schedule respects the interval and ends_on', function () {
    $payment = RecurringPayment::factory()->create([
        'day_of_month' => 10, 'interval' => 3, 'starts_on' => '2026-01-15', 'ends_on' => '2026-12-31',
    ]);

    app(GenerateOccurrences::class)->forPayment($payment, CarbonImmutable::parse('2027-12-31'));

    expect(dueDates($payment))->toBe(['2026-04-10', '2026-07-10', '2026-10-10']);
});

test('weekly schedule starts on the first matching weekday', function () {
    $payment = RecurringPayment::factory()->weekly(5)->create(['starts_on' => '2026-09-21']);

    app(GenerateOccurrences::class)->forPayment($payment, CarbonImmutable::parse('2026-10-10'));

    expect(dueDates($payment))->toBe(['2026-09-25', '2026-10-02', '2026-10-09']);
});

test('yearly schedule clamps 29 February in non-leap years', function () {
    $payment = RecurringPayment::factory()->yearly(2, 29)->create(['starts_on' => '2027-01-01']);

    app(GenerateOccurrences::class)->forPayment($payment, CarbonImmutable::parse('2028-12-31'));

    expect(dueDates($payment))->toBe(['2027-02-28', '2028-02-29']);
});

test('running the generator again does not create duplicates', function () {
    $payment = RecurringPayment::factory()->create(['starts_on' => '2026-01-01']);
    $until = CarbonImmutable::parse('2026-06-30');

    app(GenerateOccurrences::class)->forPayment($payment, $until);
    app(GenerateOccurrences::class)->handle(until: $until);

    expect($payment->occurrences()->count())->toBe(6);
});

test('paused payments get no occurrences', function () {
    $payment = RecurringPayment::factory()->inactive()->create(['starts_on' => '2026-01-01']);

    app(GenerateOccurrences::class)->handle(until: CarbonImmutable::parse('2026-06-30'));

    expect($payment->occurrences()->count())->toBe(0);
});

test('editing only rebuilds future unpaid occurrences', function () {
    $this->travelTo('2026-09-21');
    $payment = RecurringPayment::factory()->create(['amount' => 3000, 'day_of_month' => 25, 'starts_on' => '2026-08-01']);
    app(GenerateOccurrences::class)->forPayment($payment, CarbonImmutable::parse('2026-10-31'));
    $august = $payment->occurrences()->where('due_on', '2026-08-25')->sole();
    app(PayOccurrence::class)->handle($august, 3000, CarbonImmutable::parse('2026-08-25'));

    app(UpdateRecurringPayment::class)->handle($payment, ['amount' => 4500]);

    expect($payment->occurrences()->orderBy('due_on')->pluck('amount', 'due_on')->mapWithKeys(fn ($a, $d) => [substr($d, 0, 10) => $a])->all())
        ->toBe(['2026-08-25' => 3000, '2026-09-25' => 4500, '2026-10-25' => 4500])
        ->and($august->fresh()->paid_amount)->toBe(3000);
});

test('paying and undoing an occurrence creates and removes its expense', function () {
    $occurrence = RecurringOccurrence::factory()->create(['amount' => 3000]);

    app(PayOccurrence::class)->handle($occurrence, 3250, CarbonImmutable::parse('2026-09-21'));

    expect(Transaction::sole())
        ->amount->toBe(3250)
        ->source_type->toBe('recurring_occurrence')
        ->category_id->toBe($occurrence->recurringPayment->category_id);

    app(UndoOccurrencePayment::class)->handle($occurrence->fresh());

    expect(Transaction::withTrashed()->count())->toBe(0)
        ->and($occurrence->fresh()->isPaid())->toBeFalse();
});

test('skipped occurrences cannot be paid', function () {
    $occurrence = RecurringOccurrence::factory()->create();

    app(SkipOccurrence::class)->handle($occurrence);
    app(PayOccurrence::class)->handle($occurrence->fresh(), 3000, CarbonImmutable::today());
})->throws(ValidationException::class);

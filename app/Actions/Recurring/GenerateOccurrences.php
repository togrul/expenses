<?php

namespace App\Actions\Recurring;

use App\Enums\Frequency;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class GenerateOccurrences
{
    /**
     * Generate occurrences for every active recurring payment (optionally of one user).
     * Idempotent: the unique (recurring_payment_id, due_on) index prevents duplicates.
     */
    public function handle(?User $user = null, ?CarbonImmutable $until = null): int
    {
        $created = 0;

        RecurringPayment::query()
            ->active()
            ->when($user, fn ($query) => $query->whereBelongsTo($user))
            ->each(function (RecurringPayment $payment) use (&$created, $until): void {
                $created += $this->forPayment($payment, $until);
            });

        return $created;
    }

    /**
     * Lazy generation when a page opens: at most once per user per day.
     */
    public function ensureForUser(User $user): void
    {
        Cache::remember(
            "recurring-occurrences:{$user->id}:".today()->toDateString(),
            now()->endOfDay(),
            fn (): int => $this->handle($user),
        );
    }

    public function forPayment(RecurringPayment $payment, ?CarbonImmutable $until = null): int
    {
        if (! $payment->is_active || $payment->trashed()) {
            return 0;
        }

        $until ??= CarbonImmutable::today()->addDays((int) config('finance.occurrence_horizon_days'));

        $lastDueOn = $payment->occurrences()->max('due_on');
        $after = $lastDueOn ? CarbonImmutable::parse($lastDueOn) : null;

        $now = now();

        $rows = array_map(fn (CarbonImmutable $date): array => [
            'recurring_payment_id' => $payment->id,
            'due_on' => $date->toDateString(),
            'amount' => $payment->amount,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::dueDates($payment, $until, $after));

        if ($rows === []) {
            return 0;
        }

        return RecurringOccurrence::query()->insertOrIgnore($rows);
    }

    /**
     * All due dates of the schedule up to $until (and ends_on), strictly after $after.
     *
     * @return list<CarbonImmutable>
     */
    public static function dueDates(RecurringPayment $payment, CarbonImmutable $until, ?CarbonImmutable $after = null): array
    {
        $start = CarbonImmutable::parse($payment->starts_on)->startOfDay();
        $end = $payment->ends_on && $payment->ends_on->lt($until) ? CarbonImmutable::parse($payment->ends_on) : $until;
        $interval = max(1, $payment->interval);

        $dates = [];

        for ($step = 0; $step < 5000; $step++) {
            $date = self::nthDate($payment, $start, $step * $interval);

            if ($date->gt($end)) {
                break;
            }

            if ($date->lt($start) || ($after && $date->lte($after))) {
                continue;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    private static function nthDate(RecurringPayment $payment, CarbonImmutable $start, int $offset): CarbonImmutable
    {
        return match ($payment->frequency) {
            Frequency::Weekly => self::firstWeekday($start, $payment->day_of_week ?? $start->dayOfWeekIso)->addWeeks($offset),
            Frequency::Monthly => self::clampedDate(
                $start->startOfMonth()->addMonthsNoOverflow($offset),
                $payment->day_of_month ?? $start->day,
            ),
            Frequency::Yearly => self::clampedDate(
                $start->startOfYear()->addYears($offset)->setMonth($payment->month_of_year ?? $start->month),
                $payment->day_of_month ?? $start->day,
            ),
        };
    }

    private static function firstWeekday(CarbonImmutable $start, int $isoDay): CarbonImmutable
    {
        return $start->addDays(($isoDay - $start->dayOfWeekIso + 7) % 7);
    }

    /**
     * The given day in $month's month, clamped to its last day (31 → 30/28).
     */
    private static function clampedDate(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $month = $month->startOfMonth();

        return $month->setDay(min($day, $month->daysInMonth));
    }
}

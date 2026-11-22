<?php

namespace App\Actions\Debts;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerateDebtSchedule
{
    /**
     * Replace all unpaid installments with $count equal monthly installments
     * covering the remaining balance. The remainder (qəpik) goes to the last one.
     *
     * When $firstDueOn is null the installments are undated. When $dayOfMonth is
     * given each following installment falls on that day (clamped to month end).
     *
     * @return Collection<int, DebtPayment>
     */
    public function handle(Debt $debt, int $count, ?CarbonImmutable $firstDueOn = null, ?int $dayOfMonth = null): Collection
    {
        if ($count < 1 || $count > 360) {
            throw new InvalidArgumentException('Installment count must be between 1 and 360.');
        }

        return DB::transaction(function () use ($debt, $count, $firstDueOn, $dayOfMonth): Collection {
            $debt->payments()->whereNull('paid_at')->delete();

            $remaining = $debt->remaining();

            if ($remaining === 0) {
                return new Collection;
            }

            $count = min($count, $remaining);
            $dayOfMonth ??= $firstDueOn?->day;

            $payments = new Collection;

            foreach (Money::split($remaining, $count) as $index => $amount) {
                $payments->push($debt->payments()->create([
                    'amount' => $amount,
                    'due_on' => $firstDueOn ? self::monthlyDate($firstDueOn, $index, (int) $dayOfMonth) : null,
                    'sort_order' => $index,
                ]));
            }

            $debt->unsetRelation('payments');

            return $payments;
        });
    }

    /**
     * The date $monthsToAdd months after $start on $day, clamped to the month's last day.
     */
    public static function monthlyDate(CarbonImmutable $start, int $monthsToAdd, int $day): CarbonImmutable
    {
        $month = $start->startOfMonth()->addMonthsNoOverflow($monthsToAdd);

        return $month->setDay(min($day, $month->daysInMonth));
    }
}

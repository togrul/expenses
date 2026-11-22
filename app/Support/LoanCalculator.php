<?php

namespace App\Support;

use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Loan amortization in integer minor units (qəpik).
 *
 * Interest is charged on the outstanding principal ("düşən faiz"), either
 * monthly (annual rate / 12) or daily (actual days / 365 between due dates).
 * The last installment absorbs rounding so the principal parts sum exactly.
 */
final class LoanCalculator
{
    /**
     * @param  int|null  $fixedPayment  Annuity payment from the bank's schedule; computed when null.
     * @param  list<CarbonImmutable>|null  $dueDates  Required for the daily method.
     * @param  CarbonImmutable|null  $startDate  Disbursement date; start of the first daily period.
     * @return list<array{number: int, payment: int, principal: int, interest: int, balance: int}>
     */
    public static function schedule(
        int $principal,
        float $annualRatePercent,
        int $months,
        LoanScheduleType $type = LoanScheduleType::Annuity,
        ?int $fixedPayment = null,
        InterestMethod $method = InterestMethod::Monthly,
        ?array $dueDates = null,
        ?CarbonImmutable $startDate = null,
    ): array {
        if ($principal <= 0 || $months < 1 || $annualRatePercent < 0) {
            throw new InvalidArgumentException('Invalid loan parameters.');
        }

        if ($method->isDaily() && ($dueDates === null || count($dueDates) !== $months)) {
            throw new InvalidArgumentException('The daily method needs one due date per month.');
        }

        $balance = $principal;
        $rows = [];

        $annuityPayment = $type === LoanScheduleType::Annuity
            ? ($fixedPayment ?: self::annuityPayment($principal, $annualRatePercent, $months))
            : 0;
        $principalParts = $type === LoanScheduleType::Differential ? Money::split($principal, $months) : [];

        $periodStart = $startDate ?? ($dueDates[0] ?? null)?->subMonthNoOverflow();

        for ($number = 1; $number <= $months; $number++) {
            if ($method->isDaily()) {
                $dueDate = $dueDates[$number - 1];
                $days = max(0, (int) $periodStart->diffInDays($dueDate));
                $interest = (int) round($balance * $annualRatePercent / 100 * $days / $method->dayBasis());
                $periodStart = $dueDate;
            } else {
                $interest = (int) round($balance * $annualRatePercent / 100 / 12);
            }

            if ($number === $months) {
                $principalPart = $balance;
            } elseif ($type === LoanScheduleType::Annuity) {
                $principalPart = min($balance, max(0, $annuityPayment - $interest));
            } else {
                $principalPart = min($balance, $principalParts[$number - 1]);
            }

            $balance -= $principalPart;

            $rows[] = [
                'number' => $number,
                'payment' => $principalPart + $interest,
                'principal' => $principalPart,
                'interest' => $interest,
                'balance' => $balance,
            ];
        }

        return $rows;
    }

    /**
     * The fixed monthly payment of an annuity loan, rounded to the qəpik.
     */
    public static function annuityPayment(int $principal, float $annualRatePercent, int $months): int
    {
        $monthlyRate = $annualRatePercent / 100 / 12;

        if ($monthlyRate == 0.0) {
            return (int) ceil($principal / $months);
        }

        return (int) round($principal * $monthlyRate / (1 - (1 + $monthlyRate) ** -$months));
    }

    /**
     * Interest accrued on $principalLeft from $since until $until (daily, actual/$basis).
     */
    public static function accruedInterest(int $principalLeft, float $annualRatePercent, CarbonImmutable $since, CarbonImmutable $until, int $basis = 365): int
    {
        $days = max(0, (int) $since->diffInDays($until));

        return (int) round($principalLeft * $annualRatePercent / 100 * $days / $basis);
    }

    /**
     * @param  list<array{number: int, payment: int, principal: int, interest: int, balance: int}>  $rows
     * @return array{monthly: int, first: int, last: int, interest: int, total: int}
     */
    public static function summarize(array $rows): array
    {
        $interest = array_sum(array_column($rows, 'interest'));
        $principal = array_sum(array_column($rows, 'principal'));

        return [
            'monthly' => $rows[0]['payment'],
            'first' => $rows[0]['payment'],
            'last' => $rows[count($rows) - 1]['payment'],
            'interest' => $interest,
            'total' => $principal + $interest,
        ];
    }

    /**
     * @return array{monthly: int, first: int, last: int, interest: int, total: int}
     */
    public static function summary(int $principal, float $annualRatePercent, int $months, LoanScheduleType $type = LoanScheduleType::Annuity, ?int $fixedPayment = null): array
    {
        return self::summarize(self::schedule($principal, $annualRatePercent, $months, $type, $fixedPayment));
    }

    /**
     * Monthly due dates starting at $firstDueOn on the same day (clamped to month end).
     * With $shiftWeekends a Saturday/Sunday date moves to the next Monday, as banks do.
     *
     * @return list<CarbonImmutable>
     */
    public static function dueDates(CarbonImmutable $firstDueOn, int $months, bool $shiftWeekends = false): array
    {
        $dates = [];

        for ($index = 0; $index < $months; $index++) {
            $month = $firstDueOn->startOfMonth()->addMonthsNoOverflow($index);
            $date = $month->setDay(min($firstDueOn->day, $month->daysInMonth));

            while ($shiftWeekends && $date->isWeekend()) {
                $date = $date->addDay();
            }

            $dates[] = $date;
        }

        return $dates;
    }
}

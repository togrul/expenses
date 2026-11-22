<?php

namespace App\Actions\Debts;

use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use App\Models\Debt;
use App\Support\LoanCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateLoanSchedule
{
    /**
     * (Re)build the amortization schedule of a loan that has no payments yet.
     * The debt total becomes principal + all interest; due_on becomes the last installment date.
     */
    public function handle(Debt $debt, string $firstDueOn): void
    {
        if ($debt->payments()->whereNotNull('paid_at')->exists()) {
            throw ValidationException::withMessages([
                'principal' => __('Ödəniş edilmiş kreditin qrafikini yenidən qurmaq olmaz.'),
            ]);
        }

        DB::transaction(function () use ($debt, $firstDueOn): void {
            $debt->payments()->delete();

            $dates = LoanCalculator::dueDates(CarbonImmutable::parse($firstDueOn), (int) $debt->term_months, (bool) $debt->shift_weekends);

            $rows = LoanCalculator::schedule(
                (int) $debt->principal,
                (float) $debt->interest_rate,
                (int) $debt->term_months,
                $debt->schedule_type ?? LoanScheduleType::Annuity,
                $debt->monthly_payment,
                $debt->interest_method ?? InterestMethod::Monthly,
                $dates,
                $debt->started_on ? CarbonImmutable::parse($debt->started_on) : null,
            );

            foreach ($rows as $index => $row) {
                $debt->payments()->create([
                    'amount' => $row['payment'],
                    'principal_amount' => $row['principal'],
                    'interest_amount' => $row['interest'],
                    'installment_number' => $row['number'],
                    'due_on' => $dates[$index],
                    'sort_order' => $index,
                ]);
            }

            $debt->update([
                'total_amount' => array_sum(array_column($rows, 'payment')),
                'due_on' => end($dates),
            ]);

            $debt->unsetRelation('payments');
        });
    }
}

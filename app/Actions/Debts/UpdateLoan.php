<?php

namespace App\Actions\Debts;

use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use App\Models\Debt;
use App\Support\LoanCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLoan
{
    public function __construct(public GenerateLoanSchedule $generateLoanSchedule) {}

    /**
     * Descriptive fields can always change. Loan terms can change only while
     * nothing has been paid; the schedule is then rebuilt.
     *
     * @param  array{counterparty: string, title?: string|null, principal: int, interest_rate: float, term_months: int, schedule_type: string, first_due_on: string, started_on?: string|null, note?: string|null, monthly_payment?: int|null, interest_method?: string|null, shift_weekends?: bool}  $data
     */
    public function handle(Debt $debt, array $data): Debt
    {
        $termsChanged = (int) $debt->principal !== $data['principal']
            || abs((float) $debt->interest_rate - $data['interest_rate']) > 0.0001
            || (int) $debt->term_months !== $data['term_months']
            || $debt->schedule_type?->value !== $data['schedule_type']
            || $debt->monthly_payment !== ($data['monthly_payment'] ?? null)
            || ($debt->interest_method->value ?? 'monthly') !== ($data['interest_method'] ?? 'monthly')
            || $debt->shift_weekends !== (bool) ($data['shift_weekends'] ?? false)
            || (($debt->interest_method?->isDaily() ?? false) && $debt->started_on?->toDateString() !== ($data['started_on'] ?? null))
            || $debt->payments()->min('due_on') !== LoanCalculator::dueDates(CarbonImmutable::parse($data['first_due_on']), 1, (bool) ($data['shift_weekends'] ?? false))[0]->toDateString();

        $hasPaid = $debt->payments()->whereNotNull('paid_at')->exists();

        if ($termsChanged && $hasPaid) {
            throw ValidationException::withMessages([
                'principal' => __('Ödəniş edilmiş kreditin şərtlərini dəyişmək olmaz.'),
            ]);
        }

        return DB::transaction(function () use ($debt, $data, $termsChanged): Debt {
            $debt->update([
                'counterparty' => $data['counterparty'],
                'title' => $data['title'] ?? null,
                'started_on' => $data['started_on'] ?? null,
                'note' => $data['note'] ?? null,
                'principal' => $data['principal'],
                'interest_rate' => $data['interest_rate'],
                'term_months' => $data['term_months'],
                'schedule_type' => LoanScheduleType::from($data['schedule_type']),
                'monthly_payment' => $data['monthly_payment'] ?? null,
                'interest_method' => InterestMethod::tryFrom((string) ($data['interest_method'] ?? '')) ?? InterestMethod::Monthly,
                'shift_weekends' => (bool) ($data['shift_weekends'] ?? false),
            ]);

            if ($termsChanged) {
                $this->generateLoanSchedule->handle($debt, $data['first_due_on']);
            }

            return $debt;
        });
    }
}

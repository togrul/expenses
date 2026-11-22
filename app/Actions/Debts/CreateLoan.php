<?php

namespace App\Actions\Debts;

use App\Enums\DebtKind;
use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\User;
use App\Support\Preferences;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateLoan
{
    public function __construct(
        public GenerateLoanSchedule $generateLoanSchedule,
        public PayDebtPayment $payDebtPayment,
    ) {}

    /**
     * Create a bank loan with its schedule. Installments already paid before the
     * loan was entered ($data['paid_months']) are marked paid on their due dates.
     *
     * @param  array{counterparty: string, title?: string|null, principal: int, interest_rate: float, term_months: int, schedule_type: string, first_due_on: string, started_on?: string|null, note?: string|null, monthly_payment?: int|null, interest_method?: string|null, shift_weekends?: bool, paid_months?: int, record_paid_as_expense?: bool}  $data
     */
    public function handle(User $user, array $data): Debt
    {
        return DB::transaction(function () use ($user, $data): Debt {
            $debt = $user->debts()->create([
                'kind' => DebtKind::Loan,
                'counterparty' => $data['counterparty'],
                'title' => $data['title'] ?? null,
                'total_amount' => $data['principal'],
                'principal' => $data['principal'],
                'interest_rate' => $data['interest_rate'],
                'term_months' => $data['term_months'],
                'schedule_type' => LoanScheduleType::from($data['schedule_type']),
                'monthly_payment' => $data['monthly_payment'] ?? null,
                'interest_method' => InterestMethod::tryFrom((string) ($data['interest_method'] ?? '')) ?? InterestMethod::Monthly,
                'shift_weekends' => (bool) ($data['shift_weekends'] ?? false),
                'started_on' => $data['started_on'] ?? null,
                'note' => $data['note'] ?? null,
                'currency' => Preferences::currency($user),
            ]);

            $this->generateLoanSchedule->handle($debt, $data['first_due_on']);

            $paidMonths = min((int) ($data['paid_months'] ?? 0), (int) $data['term_months']);

            $debt->sortedPayments()
                ->take($paidMonths)
                ->each(fn (DebtPayment $payment) => $this->payDebtPayment->handle(
                    $payment,
                    $payment->amount,
                    CarbonImmutable::parse($payment->due_on),
                    (bool) ($data['record_paid_as_expense'] ?? false),
                ));

            return $debt->fresh() ?? $debt;
        });
    }
}

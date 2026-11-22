<?php

namespace App\Actions\Debts;

use App\Models\DebtPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDebtPayment
{
    /**
     * Edit an unpaid installment (e.g. to match the bank's schedule).
     * For loans, the interest part is the amount minus the principal part
     * and the loan total follows the schedule.
     *
     * @param  array{amount: int, due_on?: string|null, principal_amount?: int|null, note?: string|null}  $data
     */
    public function handle(DebtPayment $payment, array $data): DebtPayment
    {
        if ($payment->isPaid()) {
            throw ValidationException::withMessages(['amount' => __('Ödənilmiş hissəni redaktə etmək olmaz. Əvvəlcə ödənişi geri alın.')]);
        }

        $debt = $payment->debt;

        return DB::transaction(function () use ($payment, $data, $debt): DebtPayment {
            $attributes = [
                'amount' => $data['amount'],
                'due_on' => $data['due_on'] ?? null,
                'note' => $data['note'] ?? $payment->note,
            ];

            if ($debt->isLoan()) {
                $principal = (int) ($data['principal_amount'] ?? $payment->principal_amount);

                if ($principal > $data['amount']) {
                    throw ValidationException::withMessages(['principal_amount' => __('Əsas hissə ödənişdən çox ola bilməz.')]);
                }

                $attributes['principal_amount'] = $principal;
                $attributes['interest_amount'] = $data['amount'] - $principal;
            }

            $payment->update($attributes);

            if ($debt->isLoan()) {
                $debt->unsetRelation('payments');
                $debt->update([
                    'total_amount' => (int) $debt->payments->sum(fn (DebtPayment $p): int => $p->isPaid() ? (int) $p->paid_amount : $p->amount),
                ]);
                $debt->syncClosedState();
            }

            return $payment;
        });
    }
}

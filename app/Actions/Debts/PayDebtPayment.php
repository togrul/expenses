<?php

namespace App\Actions\Debts;

use App\Models\DebtPayment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayDebtPayment
{
    public function __construct(public RecordDebtPaymentExpense $recordDebtPaymentExpense) {}

    /**
     * Mark an installment as paid. A smaller amount is a partial payment: the
     * installment is closed with the paid amount and a new unpaid installment
     * is created for the difference.
     */
    public function handle(DebtPayment $payment, int $amount, CarbonInterface $paidOn, bool $createTransaction = true): DebtPayment
    {
        if ($payment->isPaid()) {
            throw ValidationException::withMessages(['amount' => __('Bu hissə artıq ödənilib.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Məbləğ müsbət olmalıdır.')]);
        }

        $debt = $payment->debt;

        if ($amount > $debt->remaining()) {
            throw ValidationException::withMessages(['amount' => __('Məbləğ borcun qalığından çox ola bilməz.')]);
        }

        return DB::transaction(function () use ($payment, $amount, $paidOn, $createTransaction, $debt): DebtPayment {
            $payment->paid_at = $paidOn;
            $payment->paid_amount = $amount;

            $remainderParts = [];

            if ($amount < $payment->amount && $payment->interest_amount !== null) {
                // A partial loan payment covers the interest first, then the principal.
                $interestPaid = min($amount, $payment->interest_amount);
                $remainderParts = [
                    'interest_amount' => $payment->interest_amount - $interestPaid,
                    'principal_amount' => (int) $payment->principal_amount - ($amount - $interestPaid),
                ];
                $payment->interest_amount = $interestPaid;
                $payment->principal_amount = $amount - $interestPaid;
            }

            $payment->save();

            if ($createTransaction) {
                $this->recordDebtPaymentExpense->handle($payment);
            }

            if ($amount < $payment->amount) {
                $debt->payments()->create([
                    'amount' => $payment->amount - $amount,
                    ...$remainderParts,
                    'installment_number' => $payment->installment_number,
                    'due_on' => $payment->due_on,
                    'split_from_id' => $payment->id,
                    'sort_order' => $payment->sort_order,
                    'note' => $payment->note,
                ]);
            }

            $debt->syncClosedState();

            return $payment;
        });
    }
}

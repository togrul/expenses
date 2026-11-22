<?php

namespace App\Actions\Debts;

use App\Models\DebtPayment;
use Illuminate\Support\Facades\DB;

class UndoDebtPayment
{
    /**
     * Revert a payment: delete its transaction, merge back an untouched
     * remainder installment created by a partial payment, and reopen the debt.
     */
    public function handle(DebtPayment $payment): DebtPayment
    {
        if (! $payment->isPaid()) {
            return $payment;
        }

        return DB::transaction(function () use ($payment): DebtPayment {
            $payment->transaction?->forceDelete();

            $remainder = DebtPayment::query()
                ->where('split_from_id', $payment->id)
                ->whereNull('paid_at')
                ->first();

            if ($remainder) {
                // Give the loan parts that moved to the remainder back to this installment.
                if ($payment->interest_amount !== null) {
                    $payment->interest_amount += (int) $remainder->interest_amount;
                    $payment->principal_amount = (int) $payment->principal_amount + (int) $remainder->principal_amount;
                }

                $remainder->delete();
            }

            $payment->forceFill([
                'paid_at' => null,
                'paid_amount' => null,
                'transaction_id' => null,
            ])->save();

            $payment->debt->syncClosedState();

            return $payment;
        });
    }
}

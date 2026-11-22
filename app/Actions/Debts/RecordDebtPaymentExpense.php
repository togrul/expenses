<?php

namespace App\Actions\Debts;

use App\Actions\Categories\CreateDefaultCategories;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\DebtPayment;
use Illuminate\Support\Facades\DB;

class RecordDebtPaymentExpense
{
    /**
     * Write a paid installment into expenses (dated on its payment date).
     * Does nothing when it is unpaid or already recorded.
     */
    public function handle(DebtPayment $payment): bool
    {
        if (! $payment->isPaid() || $payment->transaction_id !== null) {
            return false;
        }

        $debt = $payment->debt;

        DB::transaction(function () use ($payment, $debt): void {
            $transaction = $debt->user->transactions()->create([
                'type' => TransactionType::Expense,
                'amount' => (int) $payment->paid_amount,
                'currency' => $debt->currency,
                'category_id' => CreateDefaultCategories::debtPaymentCategory($debt->user)->id,
                'occurred_on' => $payment->paid_at,
                'note' => $debt->isLoan()
                    ? __(':name — kredit ödənişi', ['name' => $debt->counterparty])
                    : __(':name — borc ödənişi', ['name' => $debt->counterparty]),
                'source_type' => $payment->getMorphClass(),
                'source_id' => $payment->id,
            ]);

            $payment->forceFill(['transaction_id' => $transaction->id])->save();
        });

        return true;
    }

    /**
     * Record every paid-but-unrecorded installment of the given debts.
     *
     * @param  iterable<Debt>  $debts
     * @return int number of expenses created
     */
    public function forDebts(iterable $debts): int
    {
        $created = 0;

        foreach ($debts as $debt) {
            $debt->payments()
                ->whereNotNull('paid_at')
                ->whereNull('transaction_id')
                ->orderBy('paid_at')
                ->get()
                ->each(function (DebtPayment $payment) use (&$created): void {
                    $created += (int) $this->handle($payment);
                });
        }

        return $created;
    }
}

<?php

namespace App\Actions\Debts;

use App\Models\Debt;
use App\Models\DebtPayment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AddAdHocDebtPayment
{
    public function __construct(public PayDebtPayment $payDebtPayment) {}

    /**
     * Record a payment on a debt without a planned installment.
     */
    public function handle(Debt $debt, int $amount, CarbonInterface $paidOn, bool $createTransaction = true, ?string $note = null): DebtPayment
    {
        return DB::transaction(function () use ($debt, $amount, $paidOn, $createTransaction, $note): DebtPayment {
            $payment = $debt->payments()->create([
                'amount' => $amount,
                'due_on' => $paidOn,
                'note' => $note,
            ]);

            return $this->payDebtPayment->handle($payment, $amount, $paidOn, $createTransaction);
        });
    }
}

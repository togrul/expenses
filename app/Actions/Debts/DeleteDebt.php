<?php

namespace App\Actions\Debts;

use App\Models\Debt;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class DeleteDebt
{
    /**
     * Soft delete the debt together with the expense transactions created from its payments.
     */
    public function handle(Debt $debt): void
    {
        DB::transaction(function () use ($debt): void {
            Transaction::query()
                ->whereIn('id', $debt->payments()->whereNotNull('transaction_id')->select('transaction_id'))
                ->delete();

            $debt->delete();
        });
    }
}

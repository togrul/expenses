<?php

namespace App\Actions\Recurring;

use App\Models\RecurringPayment;
use Illuminate\Support\Facades\DB;

class DeleteRecurringPayment
{
    /**
     * Soft delete the definition and drop its pending occurrences. Paid ones
     * (and their expense transactions) stay as history.
     */
    public function handle(RecurringPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment->occurrences()->whereNull('paid_at')->delete();
            $payment->delete();
        });
    }
}

<?php

namespace App\Actions\Recurring;

use App\Models\RecurringOccurrence;
use Illuminate\Support\Facades\DB;

class UndoOccurrencePayment
{
    public function handle(RecurringOccurrence $occurrence): RecurringOccurrence
    {
        return DB::transaction(function () use ($occurrence): RecurringOccurrence {
            $occurrence->transaction?->forceDelete();

            $occurrence->forceFill([
                'paid_at' => null,
                'paid_amount' => null,
                'transaction_id' => null,
            ])->save();

            return $occurrence;
        });
    }
}

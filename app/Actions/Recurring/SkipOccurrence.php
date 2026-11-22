<?php

namespace App\Actions\Recurring;

use App\Models\RecurringOccurrence;
use Illuminate\Validation\ValidationException;

class SkipOccurrence
{
    /**
     * Skip ("bu ay keç") or un-skip an unpaid occurrence. Skipped ones are excluded from stats.
     */
    public function handle(RecurringOccurrence $occurrence, bool $skip = true): RecurringOccurrence
    {
        if ($occurrence->isPaid()) {
            throw ValidationException::withMessages(['occurrence' => __('Ödənilmiş ödənişi keçmək olmaz.')]);
        }

        $occurrence->update(['skipped_at' => $skip ? now() : null]);

        return $occurrence;
    }
}

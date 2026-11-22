<?php

namespace App\Actions\Recurring;

use App\Enums\TransactionType;
use App\Models\RecurringOccurrence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayOccurrence
{
    public function handle(RecurringOccurrence $occurrence, int $amount, CarbonInterface $paidOn, bool $createTransaction = true): RecurringOccurrence
    {
        if ($occurrence->isPaid() || $occurrence->isSkipped()) {
            throw ValidationException::withMessages(['amount' => __('Bu ödəniş artıq bağlanıb.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Məbləğ müsbət olmalıdır.')]);
        }

        return DB::transaction(function () use ($occurrence, $amount, $paidOn, $createTransaction): RecurringOccurrence {
            $recurring = $occurrence->recurringPayment;

            $occurrence->paid_at = $paidOn;
            $occurrence->paid_amount = $amount;

            if ($createTransaction) {
                $transaction = $recurring->user->transactions()->create([
                    'type' => TransactionType::Expense,
                    'amount' => $amount,
                    'currency' => $recurring->currency,
                    'category_id' => $recurring->category_id,
                    'occurred_on' => $paidOn,
                    'note' => $recurring->name,
                    'source_type' => $occurrence->getMorphClass(),
                    'source_id' => $occurrence->id,
                ]);

                $occurrence->transaction_id = $transaction->id;
            }

            $occurrence->save();

            return $occurrence;
        });
    }
}

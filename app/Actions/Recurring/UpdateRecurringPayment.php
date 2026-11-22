<?php

namespace App\Actions\Recurring;

use App\Actions\Transactions\EnsureCategoryBelongsToUser;
use App\Enums\TransactionType;
use App\Models\RecurringPayment;
use Illuminate\Support\Facades\DB;

class UpdateRecurringPayment
{
    public function __construct(public GenerateOccurrences $generateOccurrences) {}

    /**
     * Update the definition. Only future, unpaid and unskipped occurrences are
     * rebuilt; past and settled occurrences keep their original values.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(RecurringPayment $payment, array $data): RecurringPayment
    {
        EnsureCategoryBelongsToUser::check($payment->user, isset($data['category_id']) ? (int) $data['category_id'] : null, TransactionType::Expense->value);

        return DB::transaction(function () use ($payment, $data): RecurringPayment {
            $payment->update($data);

            $payment->occurrences()
                ->where('due_on', '>=', today())
                ->whereNull('paid_at')
                ->whereNull('skipped_at')
                ->delete();

            $this->generateOccurrences->forPayment($payment);

            return $payment;
        });
    }
}

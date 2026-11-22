<?php

namespace App\Actions\Recurring;

use App\Actions\Transactions\EnsureCategoryBelongsToUser;
use App\Enums\TransactionType;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Support\Preferences;
use Illuminate\Support\Facades\DB;

class CreateRecurringPayment
{
    public function __construct(public GenerateOccurrences $generateOccurrences) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): RecurringPayment
    {
        EnsureCategoryBelongsToUser::check($user, isset($data['category_id']) ? (int) $data['category_id'] : null, TransactionType::Expense->value);

        return DB::transaction(function () use ($user, $data): RecurringPayment {
            $payment = $user->recurringPayments()->create([
                ...$data,
                'currency' => Preferences::currency($user),
            ]);

            $this->generateOccurrences->forPayment($payment);

            return $payment;
        });
    }
}

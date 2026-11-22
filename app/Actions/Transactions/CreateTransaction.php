<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Preferences;
use Illuminate\Validation\ValidationException;

class CreateTransaction
{
    /**
     * @param  array{type: string, amount: int, category_id?: int|null, occurred_on: string, note?: string|null}  $data
     */
    public function handle(User $user, array $data): Transaction
    {
        EnsureCategoryBelongsToUser::check($user, $data['category_id'] ?? null, $data['type']);

        if ($data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => __('Məbləğ müsbət olmalıdır.')]);
        }

        return $user->transactions()->create([
            ...$data,
            'currency' => Preferences::currency($user),
        ]);
    }
}

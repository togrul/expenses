<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

class UpdateTransaction
{
    /**
     * Linked (auto-created) transactions only allow category and note changes;
     * amount, date and type are owned by their debt payment / occurrence.
     *
     * @param  array{type: string, amount: int, category_id?: int|null, occurred_on: string, note?: string|null}  $data
     */
    public function handle(Transaction $transaction, array $data): Transaction
    {
        $user = $transaction->user;

        EnsureCategoryBelongsToUser::check($user, $data['category_id'] ?? null, $transaction->isLinked() ? $transaction->type->value : $data['type']);

        if ($transaction->isLinked()) {
            $transaction->update([
                'category_id' => $data['category_id'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            return $transaction;
        }

        if ($data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => __('Məbləğ müsbət olmalıdır.')]);
        }

        $transaction->update($data);

        return $transaction;
    }
}

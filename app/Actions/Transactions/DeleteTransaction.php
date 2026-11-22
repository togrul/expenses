<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use DomainException;

class DeleteTransaction
{
    /**
     * Linked transactions must be undone from their source (debt payment / occurrence).
     *
     * @throws DomainException
     */
    public function handle(Transaction $transaction): void
    {
        if ($transaction->isLinked()) {
            throw new DomainException(__('Bu əməliyyat avtomatik yaradılıb. Onu mənbədən geri alın.'));
        }

        $transaction->delete();
    }
}

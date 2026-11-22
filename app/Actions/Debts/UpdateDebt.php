<?php

namespace App\Actions\Debts;

use App\Models\Debt;
use Illuminate\Validation\ValidationException;

class UpdateDebt
{
    /**
     * @param  array{counterparty: string, title?: string|null, total_amount: int, started_on?: string|null, due_on?: string|null, note?: string|null}  $data
     */
    public function handle(Debt $debt, array $data): Debt
    {
        if ($data['total_amount'] < $debt->paidTotal()) {
            throw ValidationException::withMessages([
                'total_amount' => __('Ümumi məbləğ artıq ödənilmiş məbləğdən az ola bilməz.'),
            ]);
        }

        $debt->update($data);
        $debt->syncClosedState();

        return $debt;
    }
}

<?php

namespace App\Actions\Debts;

use App\Enums\DebtDirection;
use App\Models\Debt;
use App\Models\User;
use App\Support\Preferences;

class CreateDebt
{
    /**
     * @param  array{counterparty: string, title?: string|null, total_amount: int, started_on?: string|null, due_on?: string|null, note?: string|null, direction?: string}  $data
     */
    public function handle(User $user, array $data): Debt
    {
        return $user->debts()->create([
            'direction' => DebtDirection::IOwe->value,
            ...$data,
            'currency' => Preferences::currency($user),
        ]);
    }
}

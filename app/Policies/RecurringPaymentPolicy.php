<?php

namespace App\Policies;

use App\Models\RecurringPayment;
use App\Models\User;

class RecurringPaymentPolicy
{
    public function view(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }

    public function update(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }

    public function delete(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }
}

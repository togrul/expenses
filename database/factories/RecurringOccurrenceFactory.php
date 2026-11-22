<?php

namespace Database\Factories;

use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringOccurrence>
 */
class RecurringOccurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_payment_id' => RecurringPayment::factory(),
            'due_on' => now()->toDateString(),
            'amount' => 3000,
        ];
    }
}

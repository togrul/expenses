<?php

namespace Database\Factories;

use App\Models\Debt;
use App\Models\DebtPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtPayment>
 */
class DebtPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debt_id' => Debt::factory(),
            'amount' => 10000,
            'due_on' => null,
        ];
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => ['due_on' => $date]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => TransactionType::Expense,
            'amount' => fake()->numberBetween(100, 50000),
            'currency' => 'AZN',
            'occurred_on' => fake()->dateTimeBetween('-1 month')->format('Y-m-d'),
            'note' => fake()->optional()->sentence(3),
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => ['type' => TransactionType::Income]);
    }

    public function expense(): static
    {
        return $this->state(fn (): array => ['type' => TransactionType::Expense]);
    }
}

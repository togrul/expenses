<?php

namespace Database\Factories;

use App\Enums\DebtDirection;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'direction' => DebtDirection::IOwe,
            'counterparty' => fake()->firstName(),
            'title' => fake()->optional()->words(2, true),
            'total_amount' => 100000,
            'currency' => 'AZN',
        ];
    }
}

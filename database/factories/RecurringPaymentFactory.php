<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPayment>
 */
class RecurringPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['İnternet', 'İcarə', 'Telefon', 'Netflix', 'İdman zalı']),
            'amount' => 3000,
            'currency' => 'AZN',
            'frequency' => Frequency::Monthly,
            'interval' => 1,
            'day_of_month' => 25,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'remind_days_before' => 3,
            'is_active' => true,
        ];
    }

    public function weekly(int $isoDay = 1): static
    {
        return $this->state(fn (): array => ['frequency' => Frequency::Weekly, 'day_of_week' => $isoDay, 'day_of_month' => null]);
    }

    public function yearly(int $month, int $day): static
    {
        return $this->state(fn (): array => ['frequency' => Frequency::Yearly, 'month_of_year' => $month, 'day_of_month' => $day]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

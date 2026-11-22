<?php

namespace Database\Seeders;

use App\Actions\Categories\CreateDefaultCategories;
use App\Actions\Debts\CreateDebt;
use App\Actions\Debts\CreateLoan;
use App\Actions\Debts\GenerateDebtSchedule;
use App\Actions\Debts\PayDebtPayment;
use App\Actions\Recurring\CreateRecurringPayment;
use App\Actions\Recurring\PayOccurrence;
use App\Enums\TransactionType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Realistic sample data: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstWhere('email', 'demo@example.com')
            ?? User::factory()->create(['name' => 'Demo', 'email' => 'demo@example.com']);

        app(CreateDefaultCategories::class)->handle($user);

        $category = fn (string $name, TransactionType $type = TransactionType::Expense): ?int => $user->categories()
            ->where('type', $type)->where('name', $name)->value('id');

        $today = CarbonImmutable::today();

        for ($i = 5; $i >= 0; $i--) {
            $month = $today->startOfMonth()->subMonthsNoOverflow($i);

            $user->transactions()->create([
                'type' => TransactionType::Income, 'amount' => 180000, 'currency' => 'AZN',
                'category_id' => $category('Maaş', TransactionType::Income), 'occurred_on' => $month->addDays(4), 'note' => 'Aylıq maaş',
            ]);

            if ($i % 2 === 0) {
                $user->transactions()->create([
                    'type' => TransactionType::Income, 'amount' => random_int(20000, 60000), 'currency' => 'AZN',
                    'category_id' => $category('Freelance', TransactionType::Income), 'occurred_on' => $month->addDays(14), 'note' => 'Layihə',
                ]);
            }

            foreach (['Qida' => [8, 3000, 9000], 'Nəqliyyat' => [4, 500, 2500], 'Əyləncə' => [2, 1500, 6000], 'Geyim' => [1, 4000, 15000]] as $name => [$count, $min, $max]) {
                for ($n = 0; $n < $count; $n++) {
                    $date = $month->addDays(random_int(0, $month->daysInMonth - 1));

                    if ($date->gt($today)) {
                        continue;
                    }

                    $user->transactions()->create([
                        'type' => TransactionType::Expense, 'amount' => random_int($min, $max), 'currency' => 'AZN',
                        'category_id' => $category($name), 'occurred_on' => $date,
                    ]);
                }
            }
        }

        $recurring = [
            ['name' => 'İcarə', 'amount' => 60000, 'category' => 'İcarə', 'day' => 1],
            ['name' => 'İnternet', 'amount' => 3000, 'category' => 'İnternet/Telefon', 'day' => 25],
            ['name' => 'İşıq pulu', 'amount' => 4000, 'category' => 'Kommunal', 'day' => 15, 'variable' => true],
            ['name' => 'İdman zalı', 'amount' => 5000, 'category' => 'Sağlamlıq', 'day' => 10],
        ];

        foreach ($recurring as $item) {
            $payment = app(CreateRecurringPayment::class)->handle($user, [
                'name' => $item['name'],
                'amount' => $item['amount'],
                'is_variable' => $item['variable'] ?? false,
                'category_id' => $category($item['category']),
                'frequency' => 'monthly',
                'interval' => 1,
                'day_of_month' => $item['day'],
                'starts_on' => $today->startOfMonth()->subMonthsNoOverflow(2)->toDateString(),
                'remind_days_before' => 3,
                'is_active' => true,
            ]);

            $payment->occurrences()->where('due_on', '<', $today->startOfMonth())->get()
                ->each(fn ($occurrence) => app(PayOccurrence::class)->handle($occurrence, $occurrence->amount, CarbonImmutable::parse($occurrence->due_on)));
        }

        $phone = app(CreateDebt::class)->handle($user, [
            'counterparty' => 'Kontakt Home', 'title' => 'Telefon krediti', 'total_amount' => 120000,
            'started_on' => $today->subMonthsNoOverflow(3)->toDateString(),
        ]);
        $installments = app(GenerateDebtSchedule::class)->handle($phone, 6, $today->subMonthsNoOverflow(2)->startOfMonth()->addDays(9));
        $installments->take(2)->each(fn ($payment) => app(PayDebtPayment::class)->handle($payment, $payment->amount, CarbonImmutable::parse($payment->due_on)));

        $friend = app(CreateDebt::class)->handle($user, [
            'counterparty' => 'Elvin', 'total_amount' => 50000, 'started_on' => $today->subMonth()->toDateString(), 'note' => 'Nağd borc',
        ]);
        $friend->payments()->create(['amount' => 20000, 'due_on' => $today->subDays(3)]);
        $friend->payments()->create(['amount' => 30000]);

        $loan = app(CreateLoan::class)->handle($user, [
            'counterparty' => 'Kapital Bank', 'title' => 'Nağd kredit', 'principal' => 500000,
            'interest_rate' => 18.0, 'term_months' => 12, 'schedule_type' => 'annuity',
            'first_due_on' => $today->subMonthsNoOverflow(2)->startOfMonth()->addDays(19)->toDateString(),
            'started_on' => $today->subMonthsNoOverflow(3)->toDateString(),
        ]);
        $loan->sortedPayments()->take(2)->each(fn ($payment) => app(PayDebtPayment::class)->handle($payment, $payment->amount, CarbonImmutable::parse($payment->due_on)));

        $this->command->info('Demo user: demo@example.com / password');
    }
}

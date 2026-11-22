<?php

namespace App\Actions\Categories;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\User;

class CreateDefaultCategories
{
    /**
     * @var array<string, list<array{name: string, color: string, icon: string, system_key?: string}>>
     */
    public const array Defaults = [
        'expense' => [
            ['name' => 'Qida', 'color' => '#f97316', 'icon' => 'shopping-cart'],
            ['name' => 'Kommunal', 'color' => '#eab308', 'icon' => 'bolt'],
            ['name' => 'İnternet/Telefon', 'color' => '#06b6d4', 'icon' => 'wifi'],
            ['name' => 'Nəqliyyat', 'color' => '#3b82f6', 'icon' => 'truck'],
            ['name' => 'İcarə', 'color' => '#8b5cf6', 'icon' => 'home'],
            ['name' => 'Sağlamlıq', 'color' => '#ef4444', 'icon' => 'heart'],
            ['name' => 'Geyim', 'color' => '#ec4899', 'icon' => 'shopping-bag'],
            ['name' => 'Əyləncə', 'color' => '#a855f7', 'icon' => 'film'],
            ['name' => 'Təhsil', 'color' => '#14b8a6', 'icon' => 'academic-cap'],
            ['name' => 'Borc ödənişi', 'color' => '#64748b', 'icon' => 'banknotes', 'system_key' => Category::DebtPaymentKey],
            ['name' => 'Digər', 'color' => '#71717a', 'icon' => 'ellipsis-horizontal'],
        ],
        'income' => [
            ['name' => 'Maaş', 'color' => '#22c55e', 'icon' => 'briefcase'],
            ['name' => 'Freelance', 'color' => '#10b981', 'icon' => 'computer-desktop'],
            ['name' => 'Bonus', 'color' => '#84cc16', 'icon' => 'gift'],
            ['name' => 'Borc qaytarılması', 'color' => '#0ea5e9', 'icon' => 'arrow-uturn-left'],
            ['name' => 'Digər', 'color' => '#71717a', 'icon' => 'ellipsis-horizontal'],
        ],
    ];

    /**
     * Create the default category set for a user. Existing names are left untouched.
     */
    public function handle(User $user): void
    {
        foreach (self::Defaults as $type => $categories) {
            foreach ($categories as $index => $category) {
                $user->categories()->firstOrCreate(
                    ['type' => $type, 'name' => $category['name']],
                    [
                        'color' => $category['color'],
                        'icon' => $category['icon'],
                        'system_key' => $category['system_key'] ?? null,
                        'sort_order' => $index,
                    ],
                );
            }
        }
    }

    /**
     * The category used for automatically created debt payment expenses.
     */
    public static function debtPaymentCategory(User $user): Category
    {
        return $user->categories()->firstOrCreate(
            ['system_key' => Category::DebtPaymentKey],
            ['type' => TransactionType::Expense, 'name' => 'Borc ödənişi', 'color' => '#64748b', 'icon' => 'banknotes'],
        );
    }
}

<?php

namespace App\Support;

final class Navigation
{
    /**
     * Main navigation shared by the desktop sidebar and the mobile tab bar.
     *
     * @return list<array{label: string, short: string, icon: string, route: string, active: string, mobile: bool}>
     */
    public static function items(): array
    {
        return [
            ['label' => __('Panel'), 'short' => __('Panel'), 'icon' => 'home', 'route' => 'dashboard', 'active' => 'dashboard', 'mobile' => true],
            ['label' => __('Əməliyyatlar'), 'short' => __('Əməliyyat'), 'icon' => 'arrows-right-left', 'route' => 'transactions.index', 'active' => 'transactions.*', 'mobile' => true],
            ['label' => __('Qarşıdakı ödənişlər'), 'short' => __('Ödənişlər'), 'icon' => 'calendar-days', 'route' => 'upcoming.index', 'active' => 'upcoming.*', 'mobile' => true],
            ['label' => __('Borclar'), 'short' => __('Borclar'), 'icon' => 'banknotes', 'route' => 'debts.index', 'active' => 'debts.*', 'mobile' => true],
            ['label' => __('Kreditlər'), 'short' => __('Kredit'), 'icon' => 'landmark', 'route' => 'loans.index', 'active' => 'loans.*', 'mobile' => true],
            ['label' => __('Daimi ödənişlər'), 'short' => __('Daimi'), 'icon' => 'arrow-path', 'route' => 'recurring.index', 'active' => 'recurring.*', 'mobile' => false],
            ['label' => __('Kateqoriyalar'), 'short' => __('Kateqoriya'), 'icon' => 'tag', 'route' => 'categories.index', 'active' => 'categories.*', 'mobile' => false],
        ];
    }
}

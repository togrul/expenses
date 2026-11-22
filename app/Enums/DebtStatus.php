<?php

namespace App\Enums;

enum DebtStatus: string
{
    case Active = 'active';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktiv'),
            self::Paid => __('Bağlanıb'),
            self::Overdue => __('Gecikir'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Paid => 'green',
            self::Overdue => 'red',
        };
    }
}

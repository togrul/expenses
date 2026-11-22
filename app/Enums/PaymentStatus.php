<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Skipped = 'skipped';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Gözləyir'),
            self::Paid => __('Ödənilib'),
            self::Skipped => __('Keçildi'),
            self::Overdue => __('Gecikir'),
        };
    }
}

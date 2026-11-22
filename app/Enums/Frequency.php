<?php

namespace App\Enums;

enum Frequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => __('Həftəlik'),
            self::Monthly => __('Aylıq'),
            self::Yearly => __('İllik'),
        };
    }

    /**
     * Number of occurrences of a single interval within a year.
     */
    public function perYear(): int
    {
        return match ($this) {
            self::Weekly => 52,
            self::Monthly => 12,
            self::Yearly => 1,
        };
    }
}

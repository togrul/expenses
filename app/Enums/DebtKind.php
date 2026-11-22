<?php

namespace App\Enums;

enum DebtKind: string
{
    case Personal = 'personal';
    case Loan = 'loan';

    public function label(): string
    {
        return match ($this) {
            self::Personal => __('Şəxsi borc'),
            self::Loan => __('Bank krediti'),
        };
    }
}

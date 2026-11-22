<?php

namespace App\Enums;

enum LoanScheduleType: string
{
    case Annuity = 'annuity';
    case Differential = 'differential';

    public function label(): string
    {
        return match ($this) {
            self::Annuity => __('Annuitet — hər ay eyni məbləğ (faiz qalığa hesablanır)'),
            self::Differential => __('Diferensial — əsas borc bərabər, ödəniş hər ay azalır'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Annuity => __('Annuitet'),
            self::Differential => __('Diferensial'),
        };
    }
}

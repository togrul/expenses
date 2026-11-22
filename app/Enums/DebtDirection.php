<?php

namespace App\Enums;

enum DebtDirection: string
{
    case IOwe = 'i_owe';
    case OwedToMe = 'owed_to_me';

    public function label(): string
    {
        return match ($this) {
            self::IOwe => __('Mənim borcum'),
            self::OwedToMe => __('Mənə borclu'),
        };
    }
}

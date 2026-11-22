<?php

namespace App\Enums;

enum InterestMethod: string
{
    case Monthly = 'monthly';
    case Daily360 = 'daily360';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Aylıq (illik faiz / 12)'),
            self::Daily360 => __('Günlük (faktiki gün / 360) — əksər banklar'),
            self::Daily => __('Günlük (faktiki gün / 365)'),
        };
    }

    /**
     * Day-count basis for daily methods, null for the monthly method.
     */
    public function dayBasis(): ?int
    {
        return match ($this) {
            self::Monthly => null,
            self::Daily360 => 360,
            self::Daily => 365,
        };
    }

    public function isDaily(): bool
    {
        return $this->dayBasis() !== null;
    }
}

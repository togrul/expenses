<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a human entered money amount ("1 234,50") and its bounds in minor units.
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(
        public int $min = 1,
        public ?int $max = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $minor = is_string($value) || is_int($value) ? Money::tryParse($value) : null;

        if ($minor === null) {
            $fail(__('Məbləğ düzgün deyil (məs. 1 234,50).'));

            return;
        }

        if ($minor < $this->min) {
            $fail(__('Məbləğ ən azı :amount olmalıdır.', ['amount' => Money::format($this->min)]));

            return;
        }

        if ($this->max !== null && $minor > $this->max) {
            $fail(__('Məbləğ :amount-dan çox ola bilməz.', ['amount' => Money::format($this->max)]));
        }
    }
}

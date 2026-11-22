<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Keeps money attributes as integer minor units.
 *
 * Integers are stored as-is (already minor units); strings are parsed as
 * human input ("12,50" → 1250). Floats are rejected to avoid rounding bugs.
 *
 * @implements CastsAttributes<int|null, mixed>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            return Money::parse($value);
        }

        throw new InvalidArgumentException("Money attribute [{$key}] must be an integer amount of minor units.");
    }
}

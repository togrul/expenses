<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts between human readable amounts ("1 234,50") and integer minor units (123450).
 */
final class Money
{
    /**
     * @var array<string, string>
     */
    public const array Symbols = [
        'AZN' => '₼',
        'USD' => '$',
        'EUR' => '€',
    ];

    /**
     * Parse a user supplied amount into minor units.
     *
     * Accepts both comma and dot as the decimal separator and spaces,
     * non-breaking spaces or a thousands separator between digit groups.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string|int|float|null $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }

        $normalized = preg_replace('/[\s\x{00A0}\x{202F}₼]/u', '', (string) $value) ?? '';

        if ($normalized === '') {
            throw new InvalidArgumentException('Amount is empty.');
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSeparator, '', $normalized);
            $normalized = str_replace($decimalSeparator, '.', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException("Invalid amount [{$value}].");
        }

        $major = (int) $matches[1];
        $minor = (int) str_pad($matches[2] ?? '0', 2, '0');

        return $major * 100 + $minor;
    }

    /**
     * Parse a value, returning null instead of throwing for invalid input.
     */
    public static function tryParse(string|int|float|null $value): ?int
    {
        try {
            return self::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Format minor units as "1 234,50 ₼".
     */
    public static function format(int $minor, ?string $currency = null, bool $withSymbol = true): string
    {
        $sign = $minor < 0 ? '−' : '';
        $absolute = abs($minor);

        $formatted = $sign.number_format(intdiv($absolute, 100), 0, ',', ' ').','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        if (! $withSymbol) {
            return $formatted;
        }

        return $formatted.' '.self::symbol($currency);
    }

    /**
     * Format minor units for an editable input ("1234,50").
     */
    public static function toInput(?int $minor): string
    {
        if ($minor === null) {
            return '';
        }

        return intdiv($minor, 100).','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function symbol(?string $currency = null): string
    {
        $currency ??= Preferences::currency();

        return self::Symbols[$currency] ?? $currency;
    }

    /**
     * Split an amount into $parts integer pieces; the remainder goes to the last part.
     *
     * @return list<int>
     */
    public static function split(int $minor, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be at least 1.');
        }

        $base = intdiv($minor, $parts);
        $pieces = array_fill(0, $parts, $base);
        $pieces[$parts - 1] += $minor - $base * $parts;

        return array_values($pieces);
    }
}

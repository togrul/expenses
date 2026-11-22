<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Reads the authenticated user's finance preferences with config defaults.
 */
final class Preferences
{
    public static function get(string $key, ?User $user = null): mixed
    {
        $user ??= Auth::user();

        $default = config("finance.defaults.{$key}");

        if (! $user instanceof User) {
            return $default;
        }

        return $user->preferences[$key] ?? $default;
    }

    public static function currency(?User $user = null): string
    {
        return (string) self::get('currency', $user);
    }

    public static function dateFormat(?User $user = null): string
    {
        return (string) self::get('date_format', $user);
    }

    public static function weekStartsOn(?User $user = null): int
    {
        return (int) self::get('week_starts_on', $user);
    }

    public static function formatDate(?CarbonInterface $date, string $fallback = '—'): string
    {
        return $date?->format(self::dateFormat()) ?? $fallback;
    }
}

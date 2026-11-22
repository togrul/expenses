<?php

use App\Support\Money;

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | All amounts are stored as integer minor units (qəpik). The currency
    | column on each table defaults to this value so multi-currency support
    | can be added later without a schema change.
    |
    */

    'currency' => env('FINANCE_CURRENCY', 'AZN'),

    'currencies' => Money::Symbols,

    /*
    |--------------------------------------------------------------------------
    | User preference defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'currency' => env('FINANCE_CURRENCY', 'AZN'),
        'date_format' => 'd.m.Y',
        'week_starts_on' => 1,
        'locale' => env('APP_LOCALE', 'az'),
        'notify_today' => true,
        'notify_tomorrow' => true,
        'notify_overdue' => true,
        'notify_monthly' => true,
        'notify_hour' => 9,
    ],

    'date_formats' => ['d.m.Y', 'd/m/Y', 'Y-m-d', 'm/d/Y'],

    'locales' => [
        'az' => 'Azərbaycan dili',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recurring occurrences
    |--------------------------------------------------------------------------
    |
    | How many days ahead recurring payment occurrences are generated.
    |
    */

    'occurrence_horizon_days' => 60,

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    */

    'backup_path' => storage_path('backups'),

    'backup_keep' => 30,

    /*
    |--------------------------------------------------------------------------
    | Push reminders
    |--------------------------------------------------------------------------
    |
    | Reminders go out once a day at the hour each user picks, in this time
    | zone. The hours are limited so "today" is the same date in UTC.
    |
    */

    'timezone' => env('FINANCE_TIMEZONE', 'Asia/Baku'),

    'notify_hours' => range(6, 22),

];

<?php

namespace App\Http\Middleware;

use App\Support\Preferences;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    /**
     * Apply the authenticated user's preferred locale to translations and dates.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $locale = (string) Preferences::get('locale', $request->user());

            if ($locale !== app()->getLocale() && array_key_exists($locale, config('finance.locales'))) {
                app()->setLocale($locale);
                Date::setLocale($locale);
            }
        }

        return $next($request);
    }
}

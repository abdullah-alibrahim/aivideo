<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestedLocale = $request->query('lang');
        if (in_array($requestedLocale, ['ar', 'en'], true)) {
            session(['app_locale' => $requestedLocale]);
        }

        $locale = session('app_locale');
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = 'ar';
            session(['app_locale' => $locale]);
        }

        app()->setLocale($locale);

        return $next($request);
    }
}

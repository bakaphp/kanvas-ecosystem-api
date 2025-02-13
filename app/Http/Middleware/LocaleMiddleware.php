<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Languages\Models\Languages;

class LocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $localeHeader = AppEnums::KANVAS_APP_CURRENT_LOCALE_CODE->getValue();
        $requestedLocale = $request->header($localeHeader);

        if ($requestedLocale) {
            $language = Languages::where('code', $requestedLocale)->first();

            if ($language) {
                app()->setLocale(strtolower($language->code));

                return $next($request);
            }
        }

        // Retrieve default locale from the app settings
        $app = app(Apps::class);
        $defaultLocale = $app->get(AppEnums::DEFAULT_APP_LOCALE->getValue()) ?? 'en';

        app()->setLocale($defaultLocale);

        return $next($request);
    }
}

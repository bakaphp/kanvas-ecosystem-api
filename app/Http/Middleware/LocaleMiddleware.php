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
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $localeHeader = AppEnums::KANVAS_APP_CURRENT_LOCALE_CODE->getValue();

        if ($request->hasHeader($localeHeader)) {
            $language = Languages::where('code', $request->header($localeHeader))->firstOrFail();

            app()->setLocale(strtolower($language->code));

            return $next($request);
        }
        //$company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        //$locale = $company->get(AppEnums::DEFAULT_COMPANY_LOCALE->getValue());
        //if (! $locale) {
        $locale = $app->get(AppEnums::DEFAULT_APP_LOCALE->getValue()) ?? app()->getLocale() ?? 'en';
        //}

        app()->setLocale($locale);

        return $next($request);
    }
}

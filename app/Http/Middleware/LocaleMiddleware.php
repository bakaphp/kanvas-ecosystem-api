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
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse) $next
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $localeHeader = AppEnums::KANVAS_APP_CURRENT_LOCALE_CODE->getValue();

        if ($request->hasHeader($localeHeader)) {
            $this->setLocaleFromHeader($request, $localeHeader);
        } else {
            $this->setLocaleFromApp();
        }

        return $next($request);
    }

    protected function setLocaleFromHeader(Request $request, string $localeHeader): void
    {
        $language = Languages::where('code', $request->header($localeHeader))->firstOrFail();
        app()->setLocale(strtolower($language->code));
    }

    protected function setLocaleFromApp(): void
    {
        $app = app(Apps::class);
        $locale = $app->get(AppEnums::DEFAULT_APP_LOCALE->getValue()) ?? app()->getLocale();
        app()->setLocale($locale);
    }
}

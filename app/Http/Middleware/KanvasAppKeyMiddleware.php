<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Baka\Support\Str;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Apps\Support\MountedAppProvider;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\AppEnums;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class KanvasAppKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $appIdentifier = $request->header(AppEnums::KANVAS_APP_HEADER->getValue(), config('kanvas.app.id'));

        if (! $this->registerApp($appIdentifier)) {
            return response()->json(['message' => 'No App configured with this key: ' . $appIdentifier], 500);
        }

        // The app key goes first because it can switch the app the branch has to belong to.
        if ($refused = $this->handleAppKey($request)) {
            return $refused;
        }

        $this->handleCompanyBranch($request);

        return $this->handleKanvasIdentifier($request) ?? $next($request);
    }

    private function registerApp(string $appIdentifier): bool
    {
        try {
            $app = AppsRepository::findFirstByKey($appIdentifier);
            (new MountedAppProvider($app))->register();

            return true;
        } catch (ModelNotFoundException $e) {
            return false;
        }
    }

    /**
     * Clients keep the last location after a logout or app switch and send it on every request, so a
     * branch that is gone or outside this app is dropped rather than refused: it is never bound, and
     * the request runs in the user's own company.
     */
    private function handleCompanyBranch(Request $request): void
    {
        $companyBranchKey = $request->header(AppEnums::KANVAS_APP_BRANCH_HEADER->getValue());

        if (empty($companyBranchKey)) {
            return;
        }

        try {
            $companyBranch = CompaniesBranches::getByUuid($companyBranchKey);
            CompaniesRepository::hasAccessToThisApp($companyBranch->company, app(Apps::class));
        } catch (Throwable) {
            return;
        }

        app()->scoped(CompaniesBranches::class, fn () => $companyBranch);
    }

    public function handleKanvasIdentifier(Request $request): ?Response
    {
        $kanvasIdentifierHeader = AppEnums::KANVAS_IDENTIFIER->getValue();

        try {
            $kanvasIdentifier = $request->header($kanvasIdentifierHeader);

            if ($kanvasIdentifier === null || empty($kanvasIdentifier) || ! Str::isUuid($kanvasIdentifier)) {
                if (auth()->user()) {
                    $kanvasIdentifier = auth()->user()->getId();
                } else {
                    return null;
                }
            }

            app()->scoped(AppEnums::KANVAS_IDENTIFIER->getValue(), fn () => $kanvasIdentifier);
        } catch (Throwable $e) {
            return response()->json(['message' => 'No App configured with this key: ' . ($kanvasIdentifier ?? 'unknown')], 500);
        }

        return null;
    }

    private function handleAppKey(Request $request): ?Response
    {
        $appKeyHeader = AppEnums::KANVAS_APP_KEY_HEADER->getValue();

        if (! empty($appKey = $request->header($appKeyHeader))) {
            try {
                $kanvasAppKey = AppKey::notDeleted()->where('client_secret_id', $appKey)->firstOrFail();
                $kanvasApp = $kanvasAppKey->app()->firstOrFail();

                if ($kanvasAppKey->hasExpired()) {
                    return response()->json(['message' => 'App Key has expired'], 500);
                }

                $this->scopeAppKeyAndApp($kanvasAppKey, $kanvasApp);
                $this->setUserIfNoBearerToken($request, $kanvasAppKey);
                $this->updateLastUsedDate($kanvasAppKey);
            } catch (Throwable $e) {
                return response()->json(['message' => 'No App Key configured with this key: ' . $appKey], 500);
            }
        }

        return null;
    }

    private function scopeAppKeyAndApp(AppKey $kanvasAppKey, Apps $kanvasApp): void
    {
        app()->scoped(AppKey::class, fn () => $kanvasAppKey);
        app()->scoped(Apps::class, fn () => $kanvasApp);
    }

    private function setUserIfNoBearerToken(Request $request, AppKey $kanvasAppKey): void
    {
        if (empty($request->bearerToken())) {
            Auth::setUser($kanvasAppKey->user()->firstOrFail());
        }
    }

    private function updateLastUsedDate(AppKey $kanvasAppKey): void
    {
        $kanvasAppKey->last_used_date = now();
        $kanvasAppKey->saveOrFail();
    }
}

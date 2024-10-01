<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Apps\Support\MountedAppProvider;
use Kanvas\Companies\Models\CompaniesBranches;
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

        $this->handleCompanyBranch($request);
        $this->handleAppKey($request);

        return $next($request);
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

    private function handleCompanyBranch(Request $request): void
    {
        $companyBranchHeader = AppEnums::KANVAS_APP_BRANCH_HEADER->getValue();

        if ($request->hasHeader($companyBranchHeader)) {
            $companyBranchKey = $request->header($companyBranchHeader);

            try {
                $companyBranch = CompaniesBranches::getByUuid($companyBranchKey);
                app()->scoped(CompaniesBranches::class, fn () => $companyBranch);
            } catch (Throwable $e) {
                response()->json(['message' => 'No Company Branch configured with this key: ' . $companyBranchKey], 500)->send();
                return ;
            }
        }
    }

    private function handleAppKey(Request $request): void
    {
        $appKeyHeader = AppEnums::KANVAS_APP_KEY_HEADER->getValue();

        if ($request->hasHeader($appKeyHeader)) {
            $appKey = $request->header($appKeyHeader);

            try {
                $kanvasAppKey = AppKey::where('client_secret_id', $appKey)->firstOrFail();
                $kanvasApp = $kanvasAppKey->app()->firstOrFail();

                if ($kanvasAppKey->hasExpired()) {
                    response()->json(['message' => 'App Key has expired'], 500)->send();
                    return ;
                }

                $this->scopeAppKeyAndApp($kanvasAppKey, $kanvasApp);
                $this->setUserIfNoBearerToken($request, $kanvasAppKey);
                $this->updateLastUsedDate($kanvasAppKey);
            } catch (Throwable $e) {
                response()->json(['message' => 'No App Key configured with this key: ' . $appKey], 500)->send();
                return ;
            }
        }
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

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\InternalServerErrorException;

class KanvasAppKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appIdentifier = $request->header(AppEnums::KANVAS_APP_HEADER->getValue(), config('kanvas.app.id'));

        try {
            $app = AppsRepository::findFirstByKey($appIdentifier);

            (new MountedAppProvider($app))->register();
        } catch (ModelNotFoundException $e) {
            throw new InternalServerErrorException(
                'No App configure with this key: ' . $appIdentifier,
                $e->getMessage()
            );
        }

        $companyBranchHeader = AppEnums::KANVAS_APP_BRANCH_HEADER->getValue();
        $appKeyHeader = AppEnums::KANVAS_APP_KEY_HEADER->getValue();

        if ($request->hasHeader($companyBranchHeader)) {
            $companyBranchKey = $request->header($companyBranchHeader);

            try {
                $companyBranch = CompaniesBranches::getByUuid($companyBranchKey);

                app()->scoped(CompaniesBranches::class, function () use ($companyBranch) {
                    return $companyBranch;
                });
            } catch (Throwable $e) {
                $msg = 'No Company Branch configure with this key: ' . $companyBranchKey;

                return response()->json(['message' => $msg], 500);
            }
        }

        if ($request->hasHeader($appKeyHeader)) {
            $appKey = $request->header($appKeyHeader);

            try {
                $kanvasAppKey = AppKey::where('client_secret_id', $appKey)->firstOrFail();
                $kanvasApp = $kanvasAppKey->app()->firstOrFail();

                if ($kanvasAppKey->hasExpired()) {
                    return response()->json(['message' => 'App Key has expired'], 500);
                }

                app()->scoped(AppKey::class, function () use ($kanvasAppKey) {
                    return $kanvasAppKey;
                });

                app()->scoped(Apps::class, function () use ($kanvasApp) {
                    return $kanvasApp;
                });

                if (empty($request->bearerToken())) {
                    Auth::setUser($kanvasAppKey->user()->firstOrFail());
                }

                $kanvasAppKey->last_used_date = date('Y-m-d H:i:s');
                $kanvasAppKey->saveOrFail();
            } catch (Throwable $e) {
                $msg = 'No App Key configure with this key: ' . $appKey;

                return response()->json(['message' => $msg], 500);
            }
        }

        return $next($request);
    }
}
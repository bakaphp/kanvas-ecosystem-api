<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Throwable;

class KanvasAppsProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //$request = new Request();
        //$domainName = $request->getHttpHost();
        $appIdentifier = request()->header(AppEnums::KANVAS_APP_HEADER->getValue(), config('kanvas.app.id'));
        $companyBranchKey = request()->header(AppEnums::KANVAS_APP_BRANCH_HEADER->getValue(), false);
        $appKey = request()->header(AppEnums::KANVAS_APP_KEY_HEADER->getValue(), false);

        if (! FacadesSchema::hasTable('apps') || ! Apps::count()) {
            throw new InternalServerErrorException('
                Kanvas Ecosystem Error no app configured , please setup your app first
            ');
        }

        try {
            $app = AppsRepository::findFirstByKey($appIdentifier);

            $this->app->scoped(Apps::class, function () use ($app) {
                return $app;
            });
        } catch (Throwable $e) {
            $msg = 'No App configure with this key: ' . $appIdentifier;

            throw new InternalServerErrorException($msg, $e->getMessage());
        }

        if ($companyBranchKey) {
            try {
                $companyBranch = CompaniesBranches::getByUuid($companyBranchKey);

                $this->app->scoped(CompaniesBranches::class, function () use ($companyBranch) {
                    return $companyBranch;
                });
            } catch (Throwable $e) {
                $msg = 'No Company Branch configure with this key: ' . $companyBranchKey;

                throw new InternalServerErrorException($msg, $e->getMessage());
            }
        }

        if ($appKey) {
            try {
                $kanvasAppKey = AppKey::where('client_secret_id', $appKey)->firstOrFail();

                $this->app->scoped(AppKey::class, function () use ($kanvasAppKey) {
                    return $kanvasAppKey;
                });
            } catch (Throwable $e) {
                $msg = 'No App Key configure with this key: ' . $appKey;

                throw new InternalServerErrorException($msg, $e->getMessage());
            }
        }
    }
}

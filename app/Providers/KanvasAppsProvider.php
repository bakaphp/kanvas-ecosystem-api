<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Throwable;

class KanvasAppsProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $request = new Request();
        $domainName = $request->getHttpHost();
        $appKey = $request->hasHeader(AppEnums::KANVAS_APP_HEADER->getValue()) ? $request->header(AppEnums::KANVAS_APP_HEADER->getValue()) : config('kanvas.app.id');

        if (FacadesSchema::hasTable('apps') && Apps::count() > 0) {
            try {
                $app = AppsRepository::findFirstByKey($appKey);

                $this->app->bind(Apps::class, function () use ($app) {
                    return $app;
                });
            } catch (Throwable $e) {
                $msg = 'No App configure with this key ' . $appKey;
                throw new InternalServerErrorException($msg, $e->getMessage());
            }
        }
    }
}

<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
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
        $domainBasedApp = (bool) env('KANVAS_CORE_DOMAIN_BASED_APP');
        $domainName = $request->getHttpHost();
        $appKey = config('kanvas.app.id');
        // $app = !$domainBasedApp ? AppsRepository::findFirstByKey($appKey) : AppsRepository::getByDomainName($domainName);
        if (FacadesSchema::hasTable('apps') && Apps::count() > 0) {
            try {
                $app = AppsRepository::findFirstByKey($appKey);

                $this->app->bind(Apps::class, function () use ($app) {
                    return $app;
                });
            } catch (Throwable $e) {
                $msg = ! $domainBasedApp ? 'No App configure with this key ' . $appKey : 'No App configure for this domain ' . $domainName;
                throw new InternalServerErrorException($msg, $e->getMessage());
            }
        }
    }
}

<?php

namespace App\Providers;

use Exception;
use Schema;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Apps\Apps\Repositories\AppsRepository;

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
        $domainBasedApp = (bool)getenv('KANVAS_CORE_DOMAIN_BASED_APP');
        $domainName = $request->getHttpHost();
        $appKey = config('kanvas.app.id');
        // $app = !$domainBasedApp ? AppsRepository::findFirstByKey($appKey) : AppsRepository::getByDomainName($domainName);
        if (Schema::hasTable('apps') && Apps::find(1) && (app()->env != 'testing')) {
            $app = AppsRepository::findFirstByKey($appKey);

            if (!$app) {
                $msg = !$domainBasedApp ? 'No App configure with this key ' . $appKey : 'No App configure by this domain ' . $domainName;
                throw new Exception($msg);
            }

            $this->app->bind(Apps::class, function () use ($app) {
                return $app;
            });
        }
    }
}

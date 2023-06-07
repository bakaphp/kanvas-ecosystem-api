<?php

namespace App\Providers;

use Bouncer;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Throwable;

class KanvasAppsProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $appIdentifier = request()->header(AppEnums::KANVAS_APP_HEADER->getValue(), config('kanvas.app.id'));

        if (App::runningInConsole() && ! FacadesSchema::hasTable('migrations')) {
            // Skip the logic if running "php artisan package:discover --ansi" for the first time
            return;
        }

        try {
            $app = AppsRepository::findFirstByKey($appIdentifier);

            $this->app->scoped(Apps::class, function () use ($app) {
                return $app;
            });

            //set app ACL scope
            Bouncer::scope()->to(RolesEnums::getScope($app));
        } catch (Throwable $e) {
            throw new InternalServerErrorException(
                'No App configure with this key: ' . $appIdentifier,
                $e->getMessage()
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Apps\Support\MountedAppProvider;
use Kanvas\Exceptions\InternalServerErrorException;
use Throwable;

class KanvasAppsProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (App::runningInConsole() && ! FacadesSchema::hasTable('migrations')) {
                // Skip the logic if running "php artisan package:discover --ansi" for the first time
                return;
            }
        } catch (Throwable $th) {
            //we've reach here on the first time the container is build , since no db connection exist
            return ;
        }

        $appIdentifier = config('kanvas.app.id');

        try {
            $app = AppsRepository::findFirstByKey($appIdentifier);

            (new MountedAppProvider($app))->register();
        } catch (ModelNotFoundException $e) {
            throw new InternalServerErrorException(
                'No App configure with this key: ' . $appIdentifier,
                $e->getMessage()
            );
        }
    }
}

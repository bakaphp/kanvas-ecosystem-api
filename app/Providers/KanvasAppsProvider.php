<?php

declare(strict_types=1);

namespace App\Providers;

use Bouncer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Throwable;
use Kanvas\Apps\Actions\MountedAppProviderAction;
class KanvasAppsProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $appIdentifier = request()->header(AppEnums::KANVAS_APP_HEADER->getValue(), config('kanvas.app.id'));

        try {
            if (App::runningInConsole() && ! FacadesSchema::hasTable('migrations')) {
                // Skip the logic if running "php artisan package:discover --ansi" for the first time
                return;
            }
        } catch (Throwable $th) {
            //we've reach here on the first time the container is build , since no db connection exist
            return ;
        }

        try {
            $app = AppsRepository::findFirstByKey($appIdentifier);

            (new MountedAppProviderAction($app))->execute();
        } catch (ModelNotFoundException $e) {
            throw new InternalServerErrorException(
                'No App configure with this key: ' . $appIdentifier,
                $e->getMessage()
            );
        }
    }
}

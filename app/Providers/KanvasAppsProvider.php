<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema as FacadesSchema;
use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Configuration\Smtp;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
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
        $appIdentifier = request()->header(AppEnums::KANVAS_APP_HEADER->getValue(), config('kanvas.app.id'));
        if (FacadesSchema::hasTable('apps') && Apps::count() > 0) {
            try {
                $app = AppsRepository::findFirstByKey($appIdentifier);
                (new Smtp($app))->load();

                $this->app->scoped(Apps::class, function () use ($app) {
                    return $app;
                });
            } catch (Throwable $e) {
                $msg = 'No App configure with this key: ' . $appIdentifier;

                throw new InternalServerErrorException($msg, $e->getMessage());
            }
        }
    }
}

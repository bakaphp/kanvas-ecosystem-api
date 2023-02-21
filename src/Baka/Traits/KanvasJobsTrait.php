<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;

trait KanvasJobsTrait
{
    /**
     * Given a app model overwrite the default laravel app service
     * so the queue doesn't use the default one
     *
     * @param AppInterface $app
     * @return void
     */
    public function overwriteAppService(AppInterface $app): void
    {
        App::scoped(Apps::class, function () use ($app) {
            return $app;
        });
    }
}

<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\AppInterface;
use Bouncer;
use Illuminate\Support\Facades\App;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;

trait KanvasJobsTrait
{
    /**
     * Given a app model overwrite the default laravel app service
     * so the queue doesn't use the default one
     */
    public function overwriteAppService(AppInterface $ap): void
    {
        $this->overWriteAppPermissionService($app);

        App::scoped(Apps::class, function () use ($app) {
            return $app;
        });
    }

    public function overwriteAppServiceLocation(CompaniesBranches $branch): void
    {
        App::scoped(CompaniesBranches::class, function () use ($branch) {
            return $branch;
        });
    }

    public function overWriteAppPermissionService(AppInterface $app): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($app));
    }
}

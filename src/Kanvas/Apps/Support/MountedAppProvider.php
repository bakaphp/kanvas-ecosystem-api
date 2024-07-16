<?php

declare(strict_types=1);

namespace Kanvas\Apps\Support;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;

class MountedAppProvider
{
    public function __construct(public Apps $app)
    {
    }
    public function execute()
    {
        $app = $this->app;
        app()->scoped(Apps::class, function () use ($app) {
            return $app;
        });

        Bouncer::scope()->to(RolesEnums::getScope($app));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;

/**
 * A second tenant for cross-app isolation tests. Deliberately skips CreateAppsAction: that
 * provisions roles, settings and modules the isolation assertions never read.
 */
trait CreatesBareApps
{
    protected function createBareApp(): Apps
    {
        $uniqueId = uniqid();
        $app = new Apps();
        $app->name = 'Other App ' . $uniqueId;
        $app->url = 'https://other-' . $uniqueId . '.example.com';
        $app->domain = 'other-' . $uniqueId . '.example.com';
        $app->description = 'Cross-tenant test app';
        $app->is_actived = 1;
        $app->ecosystem_auth = 0;
        $app->payments_active = 0;
        $app->is_public = 1;
        $app->domain_based = 0;
        $app->save();

        return $app;
    }
}

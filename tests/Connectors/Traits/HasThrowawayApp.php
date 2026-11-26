<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;

/**
 * App settings are written to Redis, which no transaction rolls back and every paratest process shares, so a
 * test that asserts on an app setting it just wrote uses an app of its own and never the shared test app.
 */
trait HasThrowawayApp
{
    protected function freshApp(): Apps
    {
        $slug = 'throwaway-' . Str::uuid();
        $app = new Apps();
        $app->fill([
            'name' => $slug,
            'url' => 'https://' . $slug . '.test',
            'description' => 'Throwaway test app',
            'domain' => $slug . '.test',
            'is_actived' => 1,
            'ecosystem_auth' => 0,
            'payments_active' => 0,
            'is_public' => 0,
            'domain_based' => 0,
        ]);
        $app->saveOrFail();

        return $app;
    }
}

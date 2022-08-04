<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Kanvas\Users\Users\Models\Users;
use Kanvas\Apps\Apps\Models\Apps;

/**
 * Create Application trait
 * 
 * @todo Find a way to login a default user to test private routes
 */
trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $kanvasApp = Apps::factory()->testing()->make();
        
        $app->bind(Apps::class, function () use ($kanvasApp) {
            return $kanvasApp;
        });

        $user = Users::factory()->create();

        $app->bind(Users::class, function () use ($user) {
            return $user;
        });

        $app->alias(Users::class, 'userData');

        return $app;
    }
}

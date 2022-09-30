<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\UsersGroup\Users\Models\Users;

/**
 * Create Application trait.
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
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        //  $kanvasApp = Apps::factory(1)->create();

        /*    $app->bind(Apps::class, function () use ($kanvasApp) {
               return $kanvasApp;
           }); */

        //$user = Users::where('id', '>', 0)->first();
        $user = Users::factory(1)->create()->first();
        $this->app = $app;
        $this->actingAs($user, 'api');

        /*    $app->bind(Users::class, function () use ($user) {
               return $user;
           });

           $app->alias(Users::class, 'userData');
 */
        return $app;
    }
}

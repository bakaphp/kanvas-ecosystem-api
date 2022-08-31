<?php

namespace Kanvas\Apps\Apps\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Apps\Actions\SetupAppsAction;
use Kanvas\Apps\Apps\Models\Apps;

class AppsObserver
{
    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function creating(Apps $app) : void
    {
        if (empty($app->key)) {
            $app->key = Str::uuid();
        }

        $app->is_deleted = 0;
    }

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function created(Apps $app) : void
    {
        $setup = new SetupAppsAction($app);
        $setup->execute();
    }
}

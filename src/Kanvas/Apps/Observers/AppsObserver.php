<?php

namespace Kanvas\Apps\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Actions\SetupAppsAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\StateEnums;

class AppsObserver
{
    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function creating(Apps $app): void
    {
        if (empty($app->key)) {
            $app->key = Str::uuid();
        }

        if (!empty($app->settings)) {
            foreach ($app->settings as $key => $value) {
                $app->set($key, $value);
            }
        }

        $app->is_deleted = StateEnums::NO->getValue();
    }

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function created(Apps $app): void
    {
        $setup = new SetupAppsAction($app);
        $setup->execute();
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Kanvas\Apps\Models\Apps;

trait AppsIdTrait
{
    /**
     * Determine if apps_id should be auto-assigned.
     *
     * @var bool
     */
    protected $autoAssignAppsId = true;

    /**
     * bootSetAppId.
     *
     * @return void
     */
    public static function bootAppsIdTrait()
    {
        static::creating(function ($model) {
            // Check if auto-assign is enabled
            if (property_exists($model, 'autoAssignAppsId') && $model->autoAssignAppsId === false) {
                return;
            }

            $model->apps_id = $model->apps_id ?? app(Apps::class)->id;
        });
    }
}

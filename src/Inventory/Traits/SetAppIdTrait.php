<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Kanvas\Apps\Models\Apps;

trait SetAppIdTrait
{
    
    /**
     * bootSetAppId
     *
     * @return void
     */
    public function bootSetAppId()
    {
        static::creating(function ($model) {
            $model->apps_id = $model->apps_id ?? app(Apps::class)->id;
        });
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\AppsGroup\Settings\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Apps\Models\Apps;

/**
 * AppsSettings Class
 *
 * @property int $apps_id
 * @property string $name
 * @property string $value
 */

class Settings extends BaseModel
{

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps_settings';

    /**
     * Apps relationship
     *
     * @return Apps
     */
    public function app(): Apps
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}

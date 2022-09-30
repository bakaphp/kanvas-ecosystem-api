<?php

declare(strict_types=1);

namespace Kanvas\AppsGroup\Roles\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Apps\Models\Apps;

/**
 * AppsRoles Class
 *
 * @property int $apps_id
 * @property string $roles_name
 */
class Roles extends BaseModel
{

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps_roles';

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

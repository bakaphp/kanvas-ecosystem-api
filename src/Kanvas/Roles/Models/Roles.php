<?php

declare(strict_types=1);

namespace Kanvas\Roles\Models;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;

/**
 * Apps Model.
 *
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string $description
 * @property int $scope
 * @property int $is_actived
 * @property int $is_default
 */
class Roles extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'roles';

    /**
     * Companies relationship.
     *
     * @return Companies
     */
    public function company() : Companies
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Apps relationship.
     *
     * @return Apps
     */
    public function app() : Apps
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}

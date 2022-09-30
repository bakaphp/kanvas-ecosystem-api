<?php

declare(strict_types=1);

namespace Kanvas\Roles\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Roles\Factories\RolesFactory;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\Apps\Apps\Models\Apps;

/**
 * Apps Model
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
    * Create a new factory instance for the model.
    *
    * @return \Illuminate\Database\Eloquent\Factories\Factory
    */
    protected static function newFactory()
    {
        return RolesFactory::new();
    }

    /**
     * Companies relationship
     *
     * @return Companies
     */
    public function company(): Companies
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

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

<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\SystemModules\Factories\SystemModulesFactory;

/**
 * Apps Model
 *
 * @property string $name
 * @property string $slug
 * @property string $model_name
 * @property int $apps_id
 * @property int $parents_id
 * @property int $menu_order
 * @property int $show
 * @property int $use_elastic
 * @property string $browse_fields
 * @property string $bulk_actions
 * @property string $mobile_component_type
 * @property string $mobile_navigation_type
 * @property int $mobile_tab_index
 * @property int $protected
 */
class SystemModules extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'system_modules';

    /**
    * Create a new factory instance for the model.
    *
    * @return \Illuminate\Database\Eloquent\Factories\Factory
    */
    protected static function newFactory()
    {
        return SystemModulesFactory::new();
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

    /**
     * Apps relationship
     *
     * @return self
     */
    public function parent(): self
    {
        return $this->belongsTo(self::class, 'parents_id');
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Roles\Models;

use Kanvas\Apps\Models\Apps;
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
    protected $table = 'roles_kanvas_legacy';
}

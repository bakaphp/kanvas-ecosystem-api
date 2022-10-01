<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Traits\HashTableTrait;
use Kanvas\Apps\Factories\AppsFactory;
use Kanvas\AppsGroup\Roles\Models\Roles;
use Kanvas\AppsGroup\Settings\Models\Settings;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\UsersGroup\CompanyApps\Models\UserCompanyApps;

/**
 * AppPlan Model.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string $payment_interval
 * @property string $description
 * @property string $stripe_id
 * @property string $stripe_plan
 * @property float $pricing
 */
class AppPlans extends BaseModel
{

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps_plans';

    /**
    * The attributes that should not be mass assignable.
    *
    * @var array
    */
    protected $guarded = [];


}

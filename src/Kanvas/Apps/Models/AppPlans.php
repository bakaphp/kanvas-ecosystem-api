<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Kanvas\Models\BaseModel;

/**
 * AppPlan Model.
 *
 * @deprecated v2
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

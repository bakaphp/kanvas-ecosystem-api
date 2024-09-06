<?php
declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Models;

use Kanvas\Models\BaseModel;

class Plan extends BaseModel
{
    protected $table = 'apps_plans';
    protected $fillable = [
        'apps_id',
        'name',
        'payment_interval',
        'description',
        'stripe_id',
        'stripe_plan',
        'pricing',
        'pricing_anual',
        'currency_id',
        'free_trial_dates',
        'is_default',
        'created_at',
        'updated_at',
    ];
}
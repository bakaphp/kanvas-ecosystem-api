<?php
declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Models;

use Kanvas\Models\BaseModel;

class SubscriptionItem extends BaseModel
{
    protected $table = 'subscription_items';
    protected $fillable = [
        'subscription_id',
        'apps_plans_id',
        'stripe_id',
        'stripe_plan',
        'quantity',
        'created_at',
        'updated_at',
        'is_deleted',
    ];
}
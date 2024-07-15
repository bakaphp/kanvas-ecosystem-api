<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Casts\Json;
use Kanvas\Guild\Models\BaseModel;

/**
 * @property int $id
 * @property int $peoples_id
 * @property string $subscription_type
 * @property string $status
 * @property datetime $first_date
 * @property datetime $start_date
 * @property datetime $end_date
 * @property datetime $next_renewal
 */
class PeopleSubscription extends BaseModel
{
    protected $table = 'peoples_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class,
    ];
}

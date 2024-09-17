<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Baka\Traits\SearchableTrait;
class SubscriptionRepository
{
    use SearchableTrait;
    
    /**
     * Get the model instance for Subscription.
     *
     * @return Model
     */
    public static function getModel(): Model
    {
        return new Subscription();
    }
}
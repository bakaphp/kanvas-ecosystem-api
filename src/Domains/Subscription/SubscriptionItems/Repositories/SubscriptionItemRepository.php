<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Baka\Traits\SearchableTrait;

class SubscriptionItemRepository
{
    use SearchableTrait;
    
    /**
     * Get the model instance for SubscriptionItem.
     *
     * @return Model
     */
    public static function getModel(): Model
    {
        return new SubscriptionItem();
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Kanvas\Subscription\Models\BaseModel;
use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Prices\Models\Price;

/**
 * Class SubscriptionItem.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $apps_plans_id
 * @property int $price_id
 * @property int $quantity
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class SubscriptionItem extends BaseModel
{
    use CascadeSoftDeletes;
    protected $table = 'subscription_items';
    protected $guarded = [];

    /**
     * subscription.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'apps_plans_id');
    }

    /**
     * price.
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return $this->{$this->getDeletedAtColumn()};
    }
}

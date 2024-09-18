<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Subscription\Models\BaseModel;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;
use Kanvas\Subscription\Prices\Models\Price;

/**
 * Class Plan.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string $description
 * @property string $stripe_id
 * @property bool $is_default
 * @property bool $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class Plan extends BaseModel
{
    protected $table = 'apps_plans';
    protected $guarded = [];

    /**
     * subscriptionItem.
     */
    public function subscriptionItem(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'apps_plans_id');
    }

    /**
     * price.
     */
    public function price(): HasMany
    {
        return $this->hasMany(Price::class, 'apps_plans_id');
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

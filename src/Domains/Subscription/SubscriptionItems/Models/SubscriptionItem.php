<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\Models;

use Baka\Traits\UuidTrait;
use Baka\Traits\SlugTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Subscription\Subscriptions\Models\Subscription;

/**
 * Class SubscriptionItem.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $product_id
 * @property int $quantity
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class SubscriptionItem extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CascadeSoftDeletes;

    protected $table = 'subscription_items';

    /**
     * subscription.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
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
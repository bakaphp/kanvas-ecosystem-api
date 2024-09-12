<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Subscription\Models\BaseModel;
use Kanvas\Subscription\SubscriptionItems\Models\SubscriptionItem;

/**
 * Class Subscription.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string $stripe_id
 * @property string $payment_method_id
 * @property string $stripe_status
 * @property string $trial_ends_at
 * @property string $next_due_payment
 * @property string $ends_at
 * @property int $trial_ends_days
 * @property bool $is_freetrial
 * @property bool $is_active
 * @property bool $is_cancelled
 * @property bool $paid
 * @property string $charge_date
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class Subscription extends BaseModel
{

    protected $table = 'subscriptions';
    protected $guarded = [];

    /**
     * apps.
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * companies.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * subscriptionItems.
     */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'subscription_id');
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
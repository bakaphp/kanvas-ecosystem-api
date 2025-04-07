<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Subscription\Models\BaseModel;
use Kanvas\Subscription\Plans\Models\Plan;

/**
 * Class Price.
 *
 * @property int $id
 * @property int $apps_plans_id
 * @property string $stripe_id
 * @property float $amount
 * @property string $currency
 * @property string $interval
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class Price extends BaseModel
{
    protected $table = 'apps_plans_prices';
    protected $guarded = [];

    /**
     * Plan relationship.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'apps_plans_id');
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

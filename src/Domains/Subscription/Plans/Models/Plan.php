<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Models;

use Baka\Traits\UuidTrait;
use Baka\Traits\SlugTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Plan.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string $payment_interval
 * @property string $description
 * @property string $stripe_id
 * @property string $stripe_plan
 * @property float $pricing
 * @property float $pricing_anual
 * @property int $currency_id
 * @property int $free_trial_dates
 * @property bool $is_default
 * @property string $created_at
 * @property string $updated_at
 */
class Plan extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CascadeSoftDeletes;

    protected $table = 'apps_plans';

    /**
     * apps.
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
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
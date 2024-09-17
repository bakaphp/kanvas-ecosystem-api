<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Subscription\Models\BaseModel;

/**
 * Class AppsStripeCustomer.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $stripe_customer_id
 * @property bool $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class AppsStripeCustomer extends BaseModel
{
    protected $table = 'apps_stripe_customers';
    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function trashed(): bool
    {
        return (bool) $this->is_deleted;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ConfigurationException;
use Kanvas\Subscription\Models\BaseModel;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;

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
    use Billable;

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

    public function createOrGetStripeCustomer(array $options = [])
    {
        $options = array_merge([
            'email' => $this->company->email ?? $this->company->user->email,
            'name' => $this->company->name,
            'phone' => $this->company->phone ?? null,
            'metadata' => [
                'kanvas_uuid' => $this->company->uuid,
            ],
        ], $options);

        if ($this->hasStripeId()) {
            return $this->asStripeCustomer($options['expand'] ?? []);
        }

        return $this->createAsStripeCustomer($options);
    }

    /**
     * Get the Stripe SDK client.
     *
     * @return \Stripe\StripeClient
     */
    public static function stripe(array $options = [])
    {
        $app = app(Apps::class);
        $options['api_key'] = $app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);

        throw_if(empty($options['api_key']), new ConfigurationException('Stripe is not configured for this app'));

        return Cashier::stripe($options);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Models;

use Kanvas\Subscription\Subscriptions\Observers\AppsStripeCustomerObserver;
use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ConfigurationException;
use Kanvas\Subscription\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Override;
use Stripe\Customer;
use Stripe\StripeClient;

/**
 * Class AppsStripeCustomer.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $stripe_id
 * @property string $provider
 * @property string $trial_ends_at
 * @property ?array $config
 * @property bool $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */

#[ObservedBy(AppsStripeCustomerObserver::class)]
class AppsStripeCustomer extends BaseModel
{
    use Billable;

    protected $table = 'apps_stripe_customers';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'config' => Json::class,
        ];
    }

    #[Override]
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    #[Override]
    public function trashed(): bool
    {
        return (bool) $this->is_deleted;
    }

    public function createOrGetStripeCustomer(array $options = []): Customer
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
     */
    public static function stripe(array $options = []): StripeClient
    {
        $app = ! isset($options['app_id']) ? app(Apps::class) : Apps::getById($options['app_id']);
        $options['api_key'] = $app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);

        throw_if(empty($options['api_key']), new ConfigurationException('Stripe is not configured for this app'));

        return Cashier::stripe($options);
    }
}

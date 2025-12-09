<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string|null $description
 * @property string $slug
 * @property bool $is_active
 * @property bool $accepts_new_affiliates
 * @property string $default_commission_type
 * @property float $default_commission_rate
 * @property bool $tier_based_commission
 * @property int $default_cookie_duration_days
 * @property int $default_attribution_window_days
 * @property bool $require_approval
 * @property float $min_payout_amount
 * @property string $payout_frequency
 * @property array|null $payout_methods_allowed
 * @property array|null $restricted_countries
 * @property array|null $restricted_categories
 * @property array|null $restricted_products
 * @property array|null $configuration
 */
class AffiliateProgram extends BaseModel
{
    use UuidTrait;

    protected $table = 'affiliate_programs';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepts_new_affiliates' => 'boolean',
            'default_commission_rate' => 'decimal:2',
            'tier_based_commission' => 'boolean',
            'default_cookie_duration_days' => 'integer',
            'default_attribution_window_days' => 'integer',
            'require_approval' => 'boolean',
            'min_payout_amount' => 'decimal:2',
            'payout_methods_allowed' => Json::class,
            'restricted_countries' => Json::class,
            'restricted_categories' => Json::class,
            'restricted_products' => Json::class,
            'configuration' => Json::class,
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(AffiliateTier::class, 'affiliate_programs_id');
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(Affiliate::class, 'affiliate_programs_id');
    }

    /**
     * Check if the program is accepting new affiliates.
     */
    public function isAcceptingAffiliates(): bool
    {
        return $this->is_active && $this->accepts_new_affiliates;
    }

    /**
     * Check if approval is required for new affiliates.
     */
    public function requiresApproval(): bool
    {
        return $this->require_approval;
    }
}

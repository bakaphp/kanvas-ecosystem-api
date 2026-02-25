<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $affiliate_programs_id
 * @property string $name
 * @property string|null $description
 * @property int $level
 * @property int $min_referrals
 * @property float|null $min_monthly_sales
 * @property float|null $min_conversion_rate
 * @property int $min_days_active
 * @property float $base_commission_rate
 * @property float $commission_multiplier
 * @property float|null $bonus_commission_rate
 * @property bool $early_payout_eligibility
 * @property bool $marketing_material_access
 * @property bool $dedicated_manager
 * @property bool $priority_support
 * @property bool $advanced_reporting
 * @property array|null $benefits
 * @property float $weight
 * @property bool $is_active
 */
class AffiliateTier extends BaseModel
{
    use UuidTrait;
    use DatabaseSearchableTrait;

    protected $table = 'affiliate_tiers';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_referrals' => 'integer',
            'min_monthly_sales' => 'decimal:2',
            'min_conversion_rate' => 'decimal:2',
            'min_days_active' => 'integer',
            'base_commission_rate' => 'decimal:2',
            'commission_multiplier' => 'decimal:2',
            'bonus_commission_rate' => 'decimal:2',
            'early_payout_eligibility' => 'boolean',
            'marketing_material_access' => 'boolean',
            'dedicated_manager' => 'boolean',
            'priority_support' => 'boolean',
            'advanced_reporting' => 'boolean',
            'benefits' => Json::class,
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgram::class, 'affiliate_programs_id');
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(Affiliate::class, 'affiliate_tiers_id');
    }

    /**
     * Check if an affiliate qualifies for this tier.
     */
    public function qualifiesForTier(int $referrals, float $monthlySales, float $conversionRate, int $daysActive): bool
    {
        if ($referrals < $this->min_referrals) {
            return false;
        }

        if ($this->min_monthly_sales !== null && $monthlySales < $this->min_monthly_sales) {
            return false;
        }

        if ($this->min_conversion_rate !== null && $conversionRate < $this->min_conversion_rate) {
            return false;
        }

        if ($daysActive < $this->min_days_active) {
            return false;
        }

        return true;
    }

    /**
     * Get the effective commission rate with multiplier.
     */
    public function getEffectiveCommissionRate(): float
    {
        return $this->base_commission_rate * $this->commission_multiplier;
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}

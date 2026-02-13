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
use Kanvas\Souk\Affiliates\Enums\AffiliateStatusEnum;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $website_url
 * @property array|null $social_profiles
 * @property string|null $bio
 * @property string|null $profile_image_url
 * @property string $affiliate_type
 * @property int $affiliate_programs_id
 * @property int|null $affiliate_tiers_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $approval_date
 * @property string|null $rejection_reason
 * @property int|null $approved_by
 * @property string $commission_type
 * @property float $commission_rate
 * @property float $min_payout_threshold
 * @property string $payout_method
 * @property string $payout_frequency
 * @property string $unique_identifier
 * @property string $tracking_method
 * @property int $cookie_duration_days
 * @property int $attribution_window_days
 * @property int $total_clicks
 * @property int $total_conversions
 * @property float $total_commission_earned
 * @property float $total_commission_paid
 * @property array|null $banking_details
 * @property array|null $paypal_details
 * @property array|null $stripe_details
 * @property array|null $configuration
 * @property \Illuminate\Support\Carbon|null $last_payout_date
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 */
class Affiliate extends BaseModel
{
    use UuidTrait;
    use DatabaseSearchableTrait;

    protected $table = 'affiliates';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'social_profiles' => Json::class,
            'approval_date' => 'datetime',
            'commission_rate' => 'decimal:2',
            'min_payout_threshold' => 'decimal:2',
            'cookie_duration_days' => 'integer',
            'attribution_window_days' => 'integer',
            'total_clicks' => 'integer',
            'total_conversions' => 'integer',
            'total_commission_earned' => 'decimal:2',
            'total_commission_paid' => 'decimal:2',
            'banking_details' => Json::class,
            'paypal_details' => Json::class,
            'stripe_details' => Json::class,
            'configuration' => Json::class,
            'last_payout_date' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgram::class, 'affiliate_programs_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(AffiliateTier::class, 'affiliate_tiers_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AffiliateLink::class, 'affiliates_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class, 'affiliates_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class, 'affiliates_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliateCommissionPayout::class, 'affiliates_id');
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(AffiliatePerformanceMetric::class, 'affiliates_id');
    }

    public function linkTemplates(): HasMany
    {
        return $this->hasMany(AffiliateLinkTemplate::class, 'affiliates_id');
    }

    /**
     * Check if affiliate is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === AffiliateStatusEnum::APPROVED->value;
    }

    /**
     * Check if affiliate is active.
     */
    public function isActive(): bool
    {
        return $this->status === AffiliateStatusEnum::ACTIVE->value;
    }

    /**
     * Check if affiliate is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === AffiliateStatusEnum::SUSPENDED->value;
    }

    /**
     * Get pending commission amount.
     */
    public function getPendingCommission(): float
    {
        return $this->total_commission_earned - $this->total_commission_paid;
    }

    /**
     * Get conversion rate.
     */
    public function getConversionRate(): float
    {
        if ($this->total_clicks === 0) {
            return 0.0;
        }

        return ((float) $this->total_conversions / (float) $this->total_clicks) * 100.0;
    }

    /**
     * Increment clicks counter.
     */
    public function incrementClicks(int $count = 1): void
    {
        $this->increment('total_clicks', $count);
        $this->last_activity_at = now();
        $this->saveOrFail();
    }

    /**
     * Increment conversions counter.
     */
    public function incrementConversions(int $count = 1): void
    {
        $this->increment('total_conversions', $count);
        $this->last_activity_at = now();
        $this->saveOrFail();
    }

    /**
     * Add commission earned.
     */
    public function addCommissionEarned(float $amount): void
    {
        $this->increment('total_commission_earned', $amount);
        $this->saveOrFail();
    }

    /**
     * Add commission paid.
     */
    public function addCommissionPaid(float $amount): void
    {
        $this->increment('total_commission_paid', $amount);
        $this->last_payout_date = now();
        $this->saveOrFail();
    }

    /**
     * Approve affiliate.
     */
    public function approve(int $approvedBy): void
    {
        $this->status = AffiliateStatusEnum::APPROVED->value;
        $this->approval_date = now();
        $this->approved_by = $approvedBy;
        $this->rejection_reason = null;
        $this->saveOrFail();
    }

    /**
     * Reject affiliate.
     */
    public function reject(string $reason): void
    {
        $this->status = AffiliateStatusEnum::REJECTED->value;
        $this->rejection_reason = $reason;
        $this->saveOrFail();
    }

    /**
     * Suspend affiliate.
     */
    public function suspend(): void
    {
        $this->status = AffiliateStatusEnum::SUSPENDED->value;
        $this->saveOrFail();
    }

    /**
     * Activate affiliate.
     */
    public function activate(): void
    {
        $this->status = AffiliateStatusEnum::ACTIVE->value;
        $this->saveOrFail();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('company.id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $affiliates_id
 * @property string|null $name
 * @property string|null $description
 * @property string|null $custom_slug
 * @property string $short_code
 * @property string|null $destination_url
 * @property string $link_type
 * @property int|null $primary_target_id
 * @property string|null $primary_target_type
 * @property array|null $config
 * @property string $tracking_method
 * @property int $cookie_duration_days
 * @property int $attribution_window_days
 * @property string $attribution_model
 * @property bool $override_commission
 * @property string|null $commission_type
 * @property float|null $commission_rate
 * @property string|null $commission_note
 * @property int $impression_count
 * @property int $click_count
 * @property int $conversion_count
 * @property float $total_order_value
 * @property float $total_commission
 * @property bool $is_active
 * @property bool $is_approved
 * @property string|null $approval_notes
 * @property array|null $configuration
 */
class AffiliateLink extends BaseModel
{
    use UuidTrait;
    use DatabaseSearchableTrait;

    protected $table = 'affiliate_links';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'config' => Json::class,
            'cookie_duration_days' => 'integer',
            'attribution_window_days' => 'integer',
            'override_commission' => 'boolean',
            'commission_rate' => 'decimal:2',
            'impression_count' => 'integer',
            'click_count' => 'integer',
            'conversion_count' => 'integer',
            'total_order_value' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'is_active' => 'boolean',
            'is_approved' => 'boolean',
            'configuration' => Json::class,
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliates_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class, 'affiliate_links_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class, 'affiliate_links_id');
    }

    public function primaryTarget(): MorphTo
    {
        return $this->morphTo('primary_target');
    }

    /**
     * Get the full tracking URL.
     */
    public function getTrackingUrl(string $baseUrl): string
    {
        if ($this->custom_slug !== null) {
            return rtrim($baseUrl, '/') . '/aff/' . $this->custom_slug;
        }

        return rtrim($baseUrl, '/') . '/aff_' . $this->short_code;
    }

    /**
     * Get conversion rate.
     */
    public function getConversionRate(): float
    {
        if ($this->click_count === 0) {
            return 0.0;
        }

        return ((float) $this->conversion_count / (float) $this->click_count) * 100.0;
    }

    /**
     * Increment impression count.
     */
    public function incrementImpressions(int $count = 1): void
    {
        $this->increment('impression_count', $count);
    }

    /**
     * Increment click count.
     */
    public function incrementClicks(int $count = 1): void
    {
        $this->increment('click_count', $count);
    }

    /**
     * Increment conversion count.
     */
    public function incrementConversions(int $count = 1): void
    {
        $this->increment('conversion_count', $count);
    }

    /**
     * Add to total order value.
     */
    public function addOrderValue(float $amount): void
    {
        $this->increment('total_order_value', $amount);
    }

    /**
     * Add to total commission.
     */
    public function addCommission(float $amount): void
    {
        $this->increment('total_commission', $amount);
    }

    /**
     * Get the commission rate for this link.
     */
    public function getCommissionRate(): ?float
    {
        if ($this->override_commission && $this->commission_rate !== null) {
            return $this->commission_rate;
        }

        $affiliate = $this->affiliate;

        return $affiliate->commission_rate;
    }

    /**
     * Get the commission type for this link.
     */
    public function getCommissionType(): string
    {
        if ($this->override_commission && $this->commission_type !== null) {
            return $this->commission_type;
        }

        $affiliate = $this->affiliate;

        return $affiliate->commission_type;
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

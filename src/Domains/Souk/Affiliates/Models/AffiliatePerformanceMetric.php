<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $affiliates_id
 * @property \Illuminate\Support\Carbon $metric_date
 * @property int $clicks
 * @property int $impressions
 * @property int $conversions
 * @property int $unique_visitors
 * @property float $order_value
 * @property float $commission_earned
 */
class AffiliatePerformanceMetric extends BaseModel
{
    use NoCompanyRelationshipTrait;

    protected $table = 'affiliate_performance_metrics';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'conversions' => 'integer',
            'unique_visitors' => 'integer',
            'order_value' => 'decimal:2',
            'commission_earned' => 'decimal:2',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliates_id');
    }

    /**
     * Get conversion rate for the day.
     */
    public function getConversionRate(): float
    {
        if ($this->clicks === 0) {
            return 0.0;
        }

        return ((float) $this->conversions / (float) $this->clicks) * 100.0;
    }

    /**
     * Get click-through rate.
     */
    public function getClickThroughRate(): float
    {
        if ($this->impressions === 0) {
            return 0.0;
        }

        return ((float) $this->clicks / (float) $this->impressions) * 100.0;
    }

    /**
     * Get average order value.
     */
    public function getAverageOrderValue(): float
    {
        if ($this->conversions === 0) {
            return 0.0;
        }

        return $this->order_value / (float) $this->conversions;
    }

    /**
     * Get earnings per click.
     */
    public function getEarningsPerClick(): float
    {
        if ($this->clicks === 0) {
            return 0.0;
        }

        return $this->commission_earned / (float) $this->clicks;
    }
}

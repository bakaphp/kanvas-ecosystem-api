<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Enums\AffiliateConversionStatusEnum;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $affiliates_id
 * @property int|null $affiliate_clicks_id
 * @property int|null $affiliate_links_id
 * @property int $orders_id
 * @property string $attribution_model
 * @property bool $confirmed
 * @property Carbon|null $confirmed_at
 * @property float $order_total
 * @property float $eligible_amount
 * @property string $commission_type
 * @property float $commission_rate
 * @property float $commission_amount
 * @property string $status
 * @property string|null $dispute_reason
 * @property Carbon|null $dispute_resolved_at
 * @property string|null $notes
 * @property int|null $commission_payout_id
 * @property Carbon|null $converted_at
 */
class AffiliateConversion extends BaseModel
{
    use UuidTrait;
    use NoCompanyRelationshipTrait;
    use CanUseWorkflow;
    use DatabaseSearchableTrait;

    protected $table = 'affiliate_conversions';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'confirmed' => 'boolean',
            'confirmed_at' => 'datetime',
            'order_total' => 'decimal:2',
            'eligible_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'dispute_resolved_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliates_id');
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(AffiliateClick::class, 'affiliate_clicks_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'affiliate_links_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AffiliateCommissionPayout::class, 'commission_payout_id');
    }

    /**
     * Check if conversion is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * Check if conversion is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === AffiliateConversionStatusEnum::PAID->value;
    }

    /**
     * Check if conversion is pending.
     */
    public function isPending(): bool
    {
        return $this->status === AffiliateConversionStatusEnum::PENDING->value;
    }

    /**
     * Check if conversion is disputed.
     */
    public function isDisputed(): bool
    {
        return $this->status === AffiliateConversionStatusEnum::DISPUTED->value;
    }

    /**
     * Confirm the conversion.
     */
    public function confirm(): void
    {
        $this->confirmed = true;
        $this->confirmed_at = now();
        $this->status = AffiliateConversionStatusEnum::CONFIRMED->value;
        $this->saveOrFail();
    }

    /**
     * Mark as paid.
     */
    public function markAsPaid(int $payoutId): void
    {
        $this->status = AffiliateConversionStatusEnum::PAID->value;
        $this->commission_payout_id = $payoutId;
        $this->saveOrFail();
    }

    /**
     * Reverse the conversion.
     */
    public function reverse(string $reason): void
    {
        $this->status = AffiliateConversionStatusEnum::REVERSED->value;
        $this->dispute_reason = $reason;
        $this->saveOrFail();
    }

    /**
     * Dispute the conversion.
     */
    public function dispute(string $reason): void
    {
        $this->status = AffiliateConversionStatusEnum::DISPUTED->value;
        $this->dispute_reason = $reason;
        $this->saveOrFail();
    }

    /**
     * Resolve dispute.
     */
    public function resolveDispute(): void
    {
        $this->status = AffiliateConversionStatusEnum::CONFIRMED->value;
        $this->dispute_resolved_at = now();
        $this->saveOrFail();
    }

    public static function search($query = '', $callback = null)
    {
        return self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
    }
}

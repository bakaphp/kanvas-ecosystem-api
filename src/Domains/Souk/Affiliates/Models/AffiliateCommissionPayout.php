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
use Illuminate\Support\Carbon;
use Kanvas\Souk\Affiliates\Enums\AffiliatePayoutStatusEnum;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $affiliates_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $total_conversions
 * @property float $total_order_value
 * @property float $gross_commission
 * @property float $fees
 * @property float $adjustments
 * @property float $net_commission
 * @property string $payout_status
 * @property string|null $payout_method
 * @property string|null $payout_reference
 * @property Carbon|null $payout_date
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $approval_notes
 * @property int|null $transaction_id
 * @property string|null $payment_intent_id
 * @property string|null $failure_reason
 * @property int $retry_count
 * @property Carbon|null $next_retry_at
 * @property array|null $configuration
 */
class AffiliateCommissionPayout extends BaseModel
{
    use UuidTrait;
    use DatabaseSearchableTrait;

    protected $table = 'affiliate_commission_payouts';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_conversions' => 'integer',
            'total_order_value' => 'decimal:2',
            'gross_commission' => 'decimal:2',
            'fees' => 'decimal:2',
            'adjustments' => 'decimal:2',
            'net_commission' => 'decimal:2',
            'payout_date' => 'date',
            'approved_at' => 'datetime',
            'retry_count' => 'integer',
            'next_retry_at' => 'datetime',
            'configuration' => Json::class,
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliates_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class, 'commission_payout_id');
    }

    /**
     * Check if payout is pending approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->payout_status === AffiliatePayoutStatusEnum::PENDING_APPROVAL->value;
    }

    /**
     * Check if payout is approved.
     */
    public function isApproved(): bool
    {
        return $this->payout_status === AffiliatePayoutStatusEnum::APPROVED->value;
    }

    /**
     * Check if payout is processing.
     */
    public function isProcessing(): bool
    {
        return $this->payout_status === AffiliatePayoutStatusEnum::PROCESSING->value;
    }

    /**
     * Check if payout is completed.
     */
    public function isCompleted(): bool
    {
        return $this->payout_status === AffiliatePayoutStatusEnum::COMPLETED->value;
    }

    /**
     * Check if payout has failed.
     */
    public function hasFailed(): bool
    {
        return $this->payout_status === AffiliatePayoutStatusEnum::FAILED->value;
    }

    /**
     * Approve payout.
     */
    public function approve(int $approvedBy, ?string $notes = null): void
    {
        $this->payout_status = AffiliatePayoutStatusEnum::APPROVED->value;
        $this->approved_at = now();
        $this->approved_by = $approvedBy;
        $this->approval_notes = $notes;
        $this->saveOrFail();
    }

    /**
     * Mark as processing.
     */
    public function markAsProcessing(): void
    {
        $this->payout_status = AffiliatePayoutStatusEnum::PROCESSING->value;
        $this->saveOrFail();
    }

    /**
     * Mark as completed.
     */
    public function markAsCompleted(string $paymentReference): void
    {
        $this->payout_status = AffiliatePayoutStatusEnum::COMPLETED->value;
        $this->payout_reference = $paymentReference;
        $this->payout_date = now();
        $this->saveOrFail();
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $reason): void
    {
        $this->payout_status = AffiliatePayoutStatusEnum::FAILED->value;
        $this->failure_reason = $reason;
        $this->increment('retry_count');
        $this->saveOrFail();
    }

    /**
     * Cancel payout.
     */
    public function cancel(): void
    {
        $this->payout_status = AffiliatePayoutStatusEnum::CANCELLED->value;
        $this->saveOrFail();
    }

    /**
     * Schedule retry.
     */
    public function scheduleRetry(\DateTimeInterface $retryAt): void
    {
        $this->next_retry_at = Carbon::parse($retryAt);
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

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Affiliates\Enums\AffiliateConversionStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AttributionModelEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateClick;
use Kanvas\Souk\Affiliates\Models\AffiliateConversion;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateAffiliateConversionAction
{
    public function __construct(
        protected Order $order
    ) {
    }

    /**
     * Execute the action to create an affiliate conversion from an order.
     *
     * The order must have custom_fields with:
     * - affiliate_id (required): The affiliate's ID
     * - affiliate_link_id (optional): The affiliate link ID if available
     *
     * Will also check metadata as a fallback for affiliate_id
     */
    public function execute(): AffiliateConversion
    {
        // Extract affiliate information from custom fields
        $affiliateId = $this->order->get('affiliate_id');
        $affiliateLinkId = $this->order->get('affiliate_link_id');

        // Fallback to metadata if not in custom fields
        if ($affiliateId === null && is_array($this->order->metadata)) {
            $affiliateId = $this->order->metadata['affiliate_id'] ?? null;

            // If found in metadata, set it to custom field for future reference
            if ($affiliateId !== null) {
                $this->order->set('affiliate_id', $affiliateId);
            }
        }

        if ($affiliateLinkId === null && is_array($this->order->metadata)) {
            $affiliateLinkId = $this->order->metadata['affiliate_link_id'] ?? null;

            // If found in metadata, set it to custom field for future reference
            if ($affiliateLinkId !== null) {
                $this->order->set('affiliate_link_id', $affiliateLinkId);
            }
        }

        if ($affiliateId === null) {
            throw new ValidationException('Order must have affiliate_id in custom_fields or metadata');
        }

        // Check if conversion already exists for this order
        $existingConversion = AffiliateConversion::where('orders_id', $this->order->id)
            ->where('affiliates_id', (int) $affiliateId)
            ->first();

        if ($existingConversion !== null) {
            return $existingConversion;
        }

        // Find the affiliate
        $affiliate = Affiliate::fromApp($this->order->app)
            ->where('id', (int) $affiliateId)
            ->firstOrFail();

        // Find the affiliate link if provided
        $affiliateLink = null;
        if ($affiliateLinkId !== null) {
            $affiliateLink = AffiliateLink::fromApp($this->order->app)
                ->where('id', (int) $affiliateLinkId)
                ->where('affiliates_id', $affiliate->id)
                ->first();
        }

        // Try to find the most recent click for this affiliate and order
        $affiliateClick = null;
        if ($affiliateLink !== null) {
            $sessionId = $this->order->get('session_id') ?? $this->order->metadata['session_id'] ?? null;
            $affiliateClick = AffiliateClick::fromApp($this->order->app)
                ->where('affiliates_id', $affiliate->id)
                ->where('affiliate_links_id', $affiliateLink->id)
                ->where('session_id', $sessionId)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        // Determine commission details
        $commissionType = $affiliateLink?->commission_type ?? $affiliate->default_commission_type;

        // Calculate commission rate based on purchase count if declining rates are configured
        $commissionRate = $this->calculateCommissionRate($affiliate, $affiliateLink);

        // Determine which attribution model to use
        $attributionModel = $affiliate->attribution_model ?? AttributionModelEnum::LAST_CLICK->value;

        // Calculate commission amount based on order total
        $orderTotal = (float) $this->order->total_gross_amount;
        $eligibleAmount = $orderTotal;

        // Calculate commission based on type
        $commissionAmount = match ($commissionType) {
            CommissionTypeEnum::PERCENTAGE->value => ($eligibleAmount * $commissionRate) / 100.0,
            CommissionTypeEnum::FIXED->value => $commissionRate,
            CommissionTypeEnum::COMPANY_WALLET->value => $commissionRate,
            CommissionTypeEnum::USER_WALLET->value => $commissionRate,
            default => ($eligibleAmount * $commissionRate) / 100.0,
        };

        return DB::connection('commerce')->transaction(function () use (
            $affiliate,
            $affiliateLink,
            $affiliateClick,
            $orderTotal,
            $eligibleAmount,
            $commissionType,
            $commissionRate,
            $commissionAmount,
            $attributionModel
        ) {
            // Create the conversion record
            $conversion = new AffiliateConversion();
            $conversion->apps_id = $this->order->app->getId();
            $conversion->affiliates_id = $affiliate->id;
            $conversion->affiliate_clicks_id = $affiliateClick?->id;
            $conversion->affiliate_links_id = $affiliateLink?->id;
            $conversion->orders_id = $this->order->id;
            $conversion->attribution_model = $attributionModel;
            $conversion->confirmed = false;
            $conversion->order_total = $orderTotal;
            $conversion->eligible_amount = $eligibleAmount;
            $conversion->commission_type = $commissionType;
            $conversion->commission_rate = $commissionRate;
            $conversion->commission_amount = $commissionAmount;
            $conversion->status = AffiliateConversionStatusEnum::PENDING->value;
            $conversion->converted_at = now();
            $conversion->saveOrFail();

            // Update affiliate metrics
            $affiliate->incrementConversions();
            $affiliate->addCommissionEarned($commissionAmount);

            // Update link metrics if available
            if ($affiliateLink !== null) {
                $affiliateLink->incrementConversions();
                $affiliateLink->addOrderValue($orderTotal);
                $affiliateLink->addCommission($commissionAmount);
            }

            // Mark click as converted if available
            if ($affiliateClick !== null) {
                $affiliateClick->markAsConverted($conversion->id);
            }

            $this->addCommissionFundsToWallet($conversion, $commissionAmount);
            $conversion->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true,
                [
                    'app' => $this->order->app,
                    'company' => $this->order->company,
                ]
            );

            return $conversion;
        });
    }

    /**
     * Calculate commission rate based on purchase count.
     *
     * Supports declining commission rates. Configure in affiliate's configuration field:
     * [
     *   'declining_commission_rates' => [
     *     1 => 25.0,  // First purchase: 25%
     *     2 => 15.0,  // Second purchase: 15%
     *     3 => 10.0,  // Third purchase: 10%
     *     4 => 5.0,   // Fourth purchase: 5%
     *     5 => 0.0    // Fifth+ purchase: 0% (no commission)
     *   ]
     * ]
     */
    protected function calculateCommissionRate(Affiliate $affiliate, ?AffiliateLink $affiliateLink): float
    {
        // Start with default commission rate
        $baseRate = (float) ($affiliateLink?->commission_rate ?? $affiliate->default_commission_rate);

        // Check if declining commission rates are configured
        $configuration = is_array($affiliate->configuration) ? $affiliate->configuration : [];

        if (! isset($configuration['declining_commission_rates'])) {
            return $baseRate;
        }

        $decliningRates = $configuration['declining_commission_rates'];

        if (! is_array($decliningRates) || empty($decliningRates)) {
            return $baseRate;
        }

        // Count existing confirmed conversions for this affiliate and order's customer
        $peopleId = $this->order->people_id;

        if ($peopleId === null) {
            return $baseRate;
        }

        // Count previous purchases by this customer through this affiliate
        $purchaseCount = AffiliateConversion::where('affiliates_id', $affiliate->id)
            ->whereHas('order', function (Builder $query) use ($peopleId) {
                $query->where('people_id', $peopleId);
            })
            ->where('confirmed', true)
            ->count();

        // This is purchase number (purchaseCount + 1)
        $currentPurchaseNumber = (int) $purchaseCount + 1;

        // Get the rate for this purchase number, or 0 if not defined
        return (float) ($decliningRates[$currentPurchaseNumber] ?? 0.0);
    }

    private function addCommissionFundsToWallet(AffiliateConversion $conversion, float $commissionAmount): void
    {
        /**
         *  we only support company wallet for now
         */
        if ($conversion->commission_type !== CommissionTypeEnum::COMPANY_WALLET->value) {
            return;
        }

        $walletHolder = $conversion->affiliate->company;
        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $walletHolder->createAppWallet($this->order->app, ['name' => $tag]);

        $transaction = $wallet->depositFloat($commissionAmount);
        $transaction->meta = [
            'affiliate_conversion_id' => $conversion->getId(),
            'affiliate_id' => $conversion->affiliates_id,
            'order_id' => $this->order->getId(),
        ];
        $transaction->saveOrFail();
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Actions;

use Kanvas\Souk\Loyalty\Models\LoyaltyTierMembership;
use Kanvas\Souk\Loyalty\Models\OrderLoyaltyPoints;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToUserWalletAction;

class AwardPointsAction
{
    public function __construct(
        private Order $order,
        private LoyaltyTierMembership $membership
    ) {
    }

    public function execute(): OrderLoyaltyPoints
    {
        $program = $this->membership->program;
        $tier = $this->membership->tier;

        // Calculate base points (supports decimal points)
        $basePoints = $this->order->total_gross_amount * $program->getPointsPerDollar();

        // Apply tier multiplier
        $earnedPoints = round($basePoints * $tier->getEarningMultiplier(), 2);

        // Create order loyalty points record
        $orderLoyaltyPoints = OrderLoyaltyPoints::create([
            'apps_id' => $this->order->apps_id,
            'orders_id' => $this->order->getId(),
            'loyalty_programs_id' => $this->membership->loyalty_programs_id,
            'loyalty_tier_memberships_id' => $this->membership->getId(),
            'points_earned' => $earnedPoints,
            'points_redeemed' => 0,
            'status' => 'pending',
        ]);

        // Award points to membership
        $this->membership->addPoints($earnedPoints);

        $useWallet = (bool) ($program->referral_config['add_to_wallet'] ?? false);
        //add point to the wallet
        if ($useWallet) {
            new AddFundsToUserWalletAction(
                order: $this->order,
                useOrderTotal: true,
                amount: $earnedPoints
            )->execute();
        }

        // Check for tier upgrade
        $this->checkTierUpgrade();

        return $orderLoyaltyPoints;
    }

    /**
     * Check if user qualifies for tier upgrade.
     */
    private function checkTierUpgrade(): void
    {
        $program = $this->membership->program;
        $currentTier = $this->membership->tier;
        $currentPoints = $this->membership->lifetime_points;

        // Find next tier
        $nextTier = $program->tiers()
            ->fromApp($this->order->app)
            ->where('level', '>', $currentTier->level)
            ->where('min_points', '<=', $currentPoints)
            ->orderBy('level')
            ->first();

        if ($nextTier && $nextTier->getId() !== $currentTier->getId()) {
            $this->membership->update([
                'loyalty_tiers_id' => $nextTier->getId(),
                'tier_promoted_at' => now(),
            ]);
        }
    }
}

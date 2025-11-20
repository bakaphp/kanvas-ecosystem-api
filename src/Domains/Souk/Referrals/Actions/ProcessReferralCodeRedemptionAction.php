<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Actions;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Referrals\Models\OrderReferralCode;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Souk\Referrals\Models\ReferralRedemption;
use Kanvas\Users\Models\Users;

class ProcessReferralCodeRedemptionAction
{
    public function __construct(
        private Order $order,
        private Users $user
    ) {
    }

    public function execute(): array
    {
        $processedRedemptions = [];

        // Get all discount codes used in this order
        $orderDiscounts = $this->order->orderDiscounts()->with('discount')->get();

        foreach ($orderDiscounts as $orderDiscount) {
            $discountCode = $orderDiscount->discount->code;

            if (! $discountCode) {
                continue;
            }

            // Check if this discount code matches a referral code
            $referralCode = ReferralCode::fromApp($this->order->app)
                ->where('code', $discountCode)
                ->where('is_active', true)
                ->first();

            if (! $referralCode || ! $referralCode->isValid()) {
                continue;
            }

            // Prevent self-referral
            if ($referralCode->users_id === $this->user->getId()) {
                continue;
            }

            // Check if this user has already used a referral code for this program
            $existingRedemption = ReferralRedemption::fromApp($this->order->app)
                ->where('referee_user_id', $this->user->getId())
                ->where('referral_codes_id', $referralCode->getId())
                ->first();

            if ($existingRedemption) {
                continue;
            }

            // Process the referral redemption
            $redemption = $this->createReferralRedemption(
                $referralCode,
                $orderDiscount->amount
            );

            // Create order referral code record
            $orderReferralCode = $this->createOrderReferralCode(
                $referralCode,
                $orderDiscount->amount
            );

            // Award points to referrer
            $this->awardPointsToReferrer($referralCode);

            // Increment referral code usage
            $referralCode->incrementUsage();

            $processedRedemptions[] = [
                'redemption' => $redemption,
                'order_referral_code' => $orderReferralCode,
                'referral_code' => $referralCode,
            ];
        }

        return $processedRedemptions;
    }

    /**
     * Create a referral redemption record.
     */
    private function createReferralRedemption(
        ReferralCode $referralCode,
        float $discountAmount
    ): ReferralRedemption {
        return ReferralRedemption::create([
            'apps_id' => $this->order->apps_id,
            'referral_codes_id' => $referralCode->getId(),
            'referrer_user_id' => $referralCode->users_id,
            'referee_user_id' => $this->user->getId(),
            'orders_id' => $this->order->getId(),
            'discounts_id' => $referralCode->discounts_id,
            'referrer_points_awarded' => $referralCode->referrer_reward,
            'referee_discount_amount' => $discountAmount,
            'status' => 'completed',
            'redeemed_at' => now(),
        ]);
    }

    /**
     * Create an order referral code record.
     */
    private function createOrderReferralCode(
        ReferralCode $referralCode,
        float $discountAmount
    ): OrderReferralCode {
        return OrderReferralCode::create([
            'apps_id' => $this->order->apps_id,
            'orders_id' => $this->order->getId(),
            'referral_codes_id' => $referralCode->getId(),
            'referrer_user_id' => $referralCode->users_id,
            'referee_user_id' => $this->user->getId(),
            'referrer_points_earned' => $referralCode->referrer_reward,
            'referee_discount_applied' => $discountAmount,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Award points to the referrer.
     */
    private function awardPointsToReferrer(ReferralCode $referralCode): void
    {
        // Get the referrer's loyalty tier membership
        $referrer = Users::find($referralCode->users_id);
        if (! $referrer) {
            return;
        }

        // Find the referrer's membership in the loyalty program
        $membership = $referrer->loyaltyTierMemberships()
            ->fromApp($this->order->app)
            ->where('loyalty_programs_id', $referralCode->loyalty_programs_id)
            ->first();

        if ($membership) {
            // Award points to the referrer
            $membership->addPoints($referralCode->referrer_reward);
        }
    }
}

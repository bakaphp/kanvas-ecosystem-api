<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Referrals\Actions\ProcessReferralCodeRedemptionAction;
use Kanvas\Users\Models\Users;

/**
 * Job to process referral code redemptions after order completion.
 */
class ProcessReferralRedemptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private int $orderId,
        private int $userId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = Order::find($this->orderId);
        $user = Users::find($this->userId);

        if (! $order || ! $user) {
            return;
        }

        // Only process completed orders
        if ($order->status !== 'completed') {
            return;
        }

        // Process referral code redemptions
        $redemptionAction = new ProcessReferralCodeRedemptionAction($order, $user);
        $processedRedemptions = $redemptionAction->execute();

        // Log the results
        foreach ($processedRedemptions as $redemption) {
            /** @var array $redemption */
            $referralCode = $redemption['referral_code'];
            $redemptionRecord = $redemption['redemption'];

            Log::info('Referral code redeemed', [
                'user_id' => $user->getId(),
                'order_id' => $order->getId(),
                'referral_code' => $referralCode->code,
                'referrer_user_id' => $referralCode->users_id,
                'referrer_points_awarded' => $referralCode->referrer_reward,
                'referee_discount_amount' => $redemptionRecord->referee_discount_amount,
                'loyalty_program_id' => $referralCode->loyalty_programs_id,
            ]);
        }

        if (count($processedRedemptions) > 0) {
            Log::info('Referral redemptions processed for order completion', [
                'user_id' => $user->getId(),
                'order_id' => $order->getId(),
                'redemptions_processed' => count($processedRedemptions),
            ]);
        }
    }
}

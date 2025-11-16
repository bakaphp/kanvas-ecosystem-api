<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Users\Models\Users;

class GenerateReferralCodeAction
{
    public function __construct(
        private Users $user,
        private LoyaltyProgram $loyaltyProgram,
        private AppInterface $app,
        private int $referrerReward = 500,
        private int $refereeReward = 100,
        private float $refereeDiscount = 10.0
    ) {
    }

    public function execute(): ReferralCode
    {
        // Check if user already has an active referral code for this program
        $existingCode = ReferralCode::where('users_id', $this->user->getId())
            ->where('loyalty_programs_id', $this->loyaltyProgram->getId())
            ->fromApp($this->app)
            ->where('is_active', true)
            ->first();

        if ($existingCode && $existingCode->isValid()) {
            return $existingCode;
        }

        // Generate unique code
        $code = $this->generateUniqueCode();

        // Create referral code
        return ReferralCode::create([
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'code' => $code,
            'loyalty_programs_id' => $this->loyaltyProgram->getId(),
            'referrer_reward' => $this->referrerReward,
            'referee_reward' => $this->refereeReward,
            'referee_discount' => $this->refereeDiscount,
            'is_active' => true,
            'strategy' => $this->loyaltyProgram->referral_strategy,
        ]);
    }

    /**
     * Generate a unique referral code using Laravel's Str::random.
     */
    private function generateUniqueCode(): string
    {
        do {
            $userPrefix = strtoupper(substr($this->user->firstname ?? 'USER', 0, 3));
            $randomSuffix = strtoupper(Str::random(4));
            $code = $userPrefix . $randomSuffix;
        } while (ReferralCode::where('code', $code)->fromApp($this->app)->exists());

        return $code;
    }
}

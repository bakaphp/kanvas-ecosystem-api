<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Discounts\Actions\CreateDiscountAction;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountData;
use Kanvas\Souk\Discounts\Models\DiscountType;
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

        // Create discount for the referral code
        $discount = $this->createDiscount($code);

        // Create referral code
        $referralCode = ReferralCode::create([
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'code' => $code,
            'loyalty_programs_id' => $this->loyaltyProgram->getId(),
            'discounts_id' => $discount->getId(),
            'referrer_reward' => $this->referrerReward,
            'referee_reward' => $this->refereeReward,
            'referee_discount' => $this->refereeDiscount,
            'is_active' => true,
            'strategy' => $this->loyaltyProgram->referral_strategy,
        ]);

        return $referralCode;
    }

    /**
     * Create a discount tied to the referral code.
     */
    private function createDiscount(string $code): mixed
    {
        $discountData = DiscountData::from([
            'name' => "Referral Discount - {$code}",
            'description' => "Discount for referral code {$code}",
            'discount_type_id' => DiscountType::getByName('Fixed Amount')->getId(),
            'value' => $this->refereeDiscount,
            'conditions' => [],
            'is_percentage' => false,
            'is_one_per_customer' => true,
            'code' => $code,
            'is_active' => true,
        ]);

        $company = Companies::getById($this->user->currentCompanyId());
        $app = $this->app instanceof \Kanvas\Apps\Models\Apps ? $this->app : \Kanvas\Apps\Models\Apps::findOrFail($this->app->getId());
        $createDiscountAction = new CreateDiscountAction($app, $company, $discountData);

        return $createDiscountAction->execute();
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

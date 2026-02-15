<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Affiliates\Enums\AffiliateStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AffiliateTypeEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutFrequencyEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutMethodEnum;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;
use Kanvas\Souk\Affiliates\Models\AffiliateTier;
use Spatie\LaravelData\Data;

class Affiliate extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly AffiliateProgram $program,
        public readonly string $name,
        public readonly string $email,
        public readonly float $commission_rate,
        public readonly ?AffiliateTier $tier = null,
        public readonly ?int $users_id = null,
        public readonly ?string $phone = null,
        public readonly ?string $website_url = null,
        public readonly mixed $social_profiles = null,
        public readonly ?string $bio = null,
        public readonly ?string $profile_image_url = null,
        public readonly AffiliateTypeEnum $affiliate_type = AffiliateTypeEnum::BUSINESS,
        public readonly AffiliateStatusEnum $status = AffiliateStatusEnum::PENDING,
        public readonly CommissionTypeEnum $commission_type = CommissionTypeEnum::PERCENTAGE,
        public readonly float $min_payout_threshold = 0,
        public readonly PayoutMethodEnum $payout_method = PayoutMethodEnum::WALLET,
        public readonly PayoutFrequencyEnum $payout_frequency = PayoutFrequencyEnum::MONTHLY,
        public readonly ?string $unique_identifier = null,
        public readonly string $tracking_method = 'utm_params',
        public readonly int $cookie_duration_days = 30,
        public readonly int $attribution_window_days = 30,
        public readonly mixed $banking_details = null,
        public readonly mixed $paypal_details = null,
        public readonly mixed $stripe_details = null,
        public readonly mixed $configuration = null,
    ) {
    }
}

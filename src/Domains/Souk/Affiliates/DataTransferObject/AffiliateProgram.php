<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutFrequencyEnum;
use Spatie\LaravelData\Data;

class AffiliateProgram extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly bool $is_active = true,
        public readonly bool $accepts_new_affiliates = true,
        public readonly bool $require_approval = true,
        public readonly CommissionTypeEnum $default_commission_type = CommissionTypeEnum::PERCENTAGE,
        public readonly float $default_commission_rate = 10.00,
        public readonly bool $tier_based_commission = false,
        public readonly int $default_cookie_duration_days = 30,
        public readonly int $default_attribution_window_days = 30,
        public readonly float $min_payout_amount = 0,
        public readonly PayoutFrequencyEnum $payout_frequency = PayoutFrequencyEnum::MONTHLY,
        public readonly mixed $payout_methods_allowed = null,
        public readonly mixed $restricted_countries = null,
        public readonly mixed $restricted_categories = null,
        public readonly mixed $restricted_products = null,
        public readonly mixed $configuration = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;
use Spatie\LaravelData\Data;

class AffiliateTier extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly AffiliateProgram $program,
        public readonly string $name,
        public readonly int $level,
        public readonly float $base_commission_rate,
        public readonly ?string $description = null,
        public readonly float $weight = 0,
        public readonly int $min_referrals = 0,
        public readonly ?float $min_monthly_sales = null,
        public readonly ?float $min_conversion_rate = null,
        public readonly int $min_days_active = 0,
        public readonly float $commission_multiplier = 1.00,
        public readonly ?float $bonus_commission_rate = null,
        public readonly bool $early_payout_eligibility = false,
        public readonly bool $marketing_material_access = false,
        public readonly bool $dedicated_manager = false,
        public readonly bool $priority_support = false,
        public readonly bool $advanced_reporting = false,
        public readonly mixed $benefits = null,
        public readonly bool $is_active = true,
    ) {
    }
}

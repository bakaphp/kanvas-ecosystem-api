<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Affiliates\Enums\AffiliatePayoutStatusEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutMethodEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Spatie\LaravelData\Data;

class AffiliateCommissionPayout extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Affiliate $affiliate,
        public readonly string $period_start,
        public readonly string $period_end,
        public readonly int $total_conversions,
        public readonly float $total_order_value,
        public readonly float $gross_commission,
        public readonly float $net_commission,
        public readonly float $fees = 0,
        public readonly float $adjustments = 0,
        public readonly AffiliatePayoutStatusEnum $payout_status = AffiliatePayoutStatusEnum::PENDING_APPROVAL,
        public readonly ?PayoutMethodEnum $payout_method = null,
        public readonly ?string $approval_notes = null,
        public readonly mixed $configuration = null,
    ) {
    }
}

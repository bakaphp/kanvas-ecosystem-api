<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Affiliates\Enums\AttributionModelEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Spatie\LaravelData\Data;

class AffiliateLink extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Affiliate $affiliate,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $custom_slug = null,
        public readonly ?string $short_code = null,
        public readonly ?string $destination_url = null,
        public readonly string $link_type = 'general',
        public readonly mixed $config = null,
        public readonly string $tracking_method = 'utm_params',
        public readonly int $cookie_duration_days = 30,
        public readonly int $attribution_window_days = 30,
        public readonly AttributionModelEnum $attribution_model = AttributionModelEnum::LAST_CLICK,
        public readonly bool $override_commission = false,
        public readonly ?CommissionTypeEnum $commission_type = null,
        public readonly ?float $commission_rate = null,
        public readonly ?string $commission_note = null,
        public readonly bool $is_active = true,
        public readonly bool $is_approved = false,
        public readonly ?string $approval_notes = null,
        public readonly mixed $configuration = null,
    ) {
    }
}

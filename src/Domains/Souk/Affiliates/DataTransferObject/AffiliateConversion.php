<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Affiliates\Enums\AffiliateConversionStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AttributionModelEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Kanvas\Souk\Orders\Models\Order;
use Spatie\LaravelData\Data;

class AffiliateConversion extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly UserInterface $user,
        public readonly Affiliate $affiliate,
        public readonly Order $order,
        public readonly float $order_total,
        public readonly float $eligible_amount,
        public readonly CommissionTypeEnum $commission_type,
        public readonly float $commission_rate,
        public readonly float $commission_amount,
        public readonly ?AffiliateLink $link = null,
        public readonly AttributionModelEnum $attribution_model = AttributionModelEnum::LAST_CLICK,
        public readonly AffiliateConversionStatusEnum $status = AffiliateConversionStatusEnum::PENDING,
        public readonly ?string $notes = null,
        public readonly ?string $converted_at = null,
    ) {
    }
}

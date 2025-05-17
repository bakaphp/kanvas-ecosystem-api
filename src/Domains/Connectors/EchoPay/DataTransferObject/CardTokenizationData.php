<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class CardTokenizationData extends Data
{
    public function __construct(
        public readonly CardDetailData $card,
        public readonly BillingDetailData $billTo,
        public readonly MerchantDetailData $merchant,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class CardTokenization extends Data
{
    public function __construct(
        public readonly CardDetail $card,
        public readonly BillingDetail $billTo,
        public readonly MerchantDetail $merchant,
    ) {
    }
}

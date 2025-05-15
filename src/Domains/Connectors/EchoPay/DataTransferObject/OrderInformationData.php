<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class OrderInformationData extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly string $totalAmount,
        public readonly BillingDetailData $billTo,
    ) {
    }
}

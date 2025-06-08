<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class OrderInformation extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly string $totalAmount,
        public readonly ?BillingDetail $billTo,
    ) {
    }
}

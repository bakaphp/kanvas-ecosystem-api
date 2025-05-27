<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class VerifyPaymentResponse extends Data
{
    public function __construct(
        public readonly string $availableToCancel,
        public readonly string $exists,
        public readonly string $applied,
        public readonly string $description,
        public readonly BillingDetail $billingDetail,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

class PaymentFailure
{
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?string $processorResponseCode = null,
        public readonly ?string $responseInsight = null,
    ) {
    }
}

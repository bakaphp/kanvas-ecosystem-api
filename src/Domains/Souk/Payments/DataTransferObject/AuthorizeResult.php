<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class AuthorizeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $transactionId,
        public readonly PaymentStatusEnum $paymentStatus,
        public readonly array $raw = [],
    ) {
    }
}

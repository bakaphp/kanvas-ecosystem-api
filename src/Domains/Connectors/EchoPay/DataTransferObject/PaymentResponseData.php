<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class PaymentResponseData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $merchantKey,
        public readonly string $serviceCode,
        public readonly string $contract,
        public readonly string $transactionId,
        public readonly string $referenceCode,
        public readonly string $approvalCode,
        public readonly string $amount,
        public readonly ?string $cardNumber,
        public readonly string $transactionStatus,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly PaymentStatusData $status,
    ) {
    }
}

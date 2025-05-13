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

    public static function fromArray(array $data): self
    {
        return new self(
            $data['availableToCancel'],
            $data['exists'],
            $data['applied'],
            $data['description'],
            BillingDetail::fromArray($data['billingDetail']),
        );
    }
}

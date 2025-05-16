<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class PaymentConfirmData extends Data
{
    public function __construct(
        public readonly string $reference,
        public readonly string $bankTransaction,
        public readonly float $amount,
        public readonly bool $fiscalCredit,
        public readonly string $dni
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['reference'],
            $data['bankTransaction'],
            $data['amount'],
            $data['fiscalCredit'],
            $data['dni']
        );
    }
}

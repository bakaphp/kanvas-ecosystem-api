<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class InvoiceDetails extends Data
{
    public function __construct(
        public readonly string $commercialName,
        public readonly string $document,
        public readonly bool $fiscalCredit,
        public readonly string $invoice,
        public readonly string $pdf,
        public readonly string $reference
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['nombreComercial'],
            $data['rncCedula'],
            $data['valorFiscal'],
            $data['comprobante'],
            $data['pdf'],
            $data['referencia']
        );
    }
}

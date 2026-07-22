<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use InvalidArgumentException;
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
        // el spec marca rnc_Cedula como requerido solo si creditoFiscal es true
        if ($fiscalCredit && trim($dni) === '') {
            throw new InvalidArgumentException('PasoRapido requires a RNC/cedula when fiscal credit is requested');
        }
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

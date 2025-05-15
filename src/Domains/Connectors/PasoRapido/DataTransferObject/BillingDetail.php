<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class BillingDetail extends Data
{
    public function __construct(
        public readonly string $document,
        public readonly bool $fiscalCredit,
        public readonly string $invoice,
        public readonly string $pdf,
    ) {
    }
}

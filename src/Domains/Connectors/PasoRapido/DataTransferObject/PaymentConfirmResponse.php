<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class PaymentConfirmResponse extends Data
{
    public function __construct(
        public readonly string $message,
        public readonly float $amount,
        public readonly int $order,
        public readonly string $tag,
        public readonly int $account,
        public readonly string $creditDate,
        public readonly InvoiceDetails $invoiceDetails,
        public readonly string $reference
    ) {
    }
}

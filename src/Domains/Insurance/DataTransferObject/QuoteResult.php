<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class QuoteResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $quoteNumber,
        public readonly ?float $premium = null,
        /** Pay-per-km products only. A rate, not an amount — never fold into premium. */
        public readonly ?float $ratePerKm = null,
        public readonly ?float $tax = null,
        public readonly ?float $total = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {
    }
}

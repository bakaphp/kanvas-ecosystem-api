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
        /**
         * What the intermediary earns on this policy. Insurers that publish it at
         * quote time let the marketplace price its own margin before selling, rather
         * than discovering it on a settlement report weeks later.
         */
        public readonly ?float $commission = null,
        /**
         * The insurer's own verdict that this quote can become a policy. Null means
         * the insurer does not say, which is not the same as "no".
         */
        public readonly ?bool $canEmit = null,
        /** The insurer's inspection state for this quote, in their own words. */
        public readonly ?string $inspectionStatus = null,
        public readonly array $raw = [],
        public readonly ?string $productCode = null,
    ) {
    }

    public function withoutRaw(): self
    {
        return new self(
            success: $this->success,
            message: $this->message,
            quoteNumber: $this->quoteNumber,
            premium: $this->premium,
            ratePerKm: $this->ratePerKm,
            tax: $this->tax,
            total: $this->total,
            currency: $this->currency,
            commission: $this->commission,
            canEmit: $this->canEmit,
            inspectionStatus: $this->inspectionStatus,
            raw: [],
            productCode: $this->productCode,
        );
    }
}

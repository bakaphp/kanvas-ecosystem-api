<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsurancePaymentTermEnum;

class QuoteResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $quoteNumber,
        public readonly ?float $premium = null,
        public readonly ?float $ratePerKm = null,
        public readonly ?float $tax = null,
        public readonly ?float $total = null,
        public readonly ?string $currency = null,
        public readonly ?float $commission = null,
        public readonly ?bool $canEmit = null,
        public readonly ?string $inspectionStatus = null,
        public readonly array $raw = [],
        public readonly ?string $productCode = null,
        public readonly array $assumptions = [],
        public readonly array $paymentOptions = [],
    ) {
    }

    public function paymentOption(InsurancePaymentTermEnum $term): ?InsurancePaymentOption
    {
        foreach ($this->paymentOptions as $option) {
            if ($option->term === $term) {
                return $option;
            }
        }

        return null;
    }

    public function withAssumptions(array $assumptions): self
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
            raw: $this->raw,
            productCode: $this->productCode,
            assumptions: $assumptions,
            paymentOptions: $this->paymentOptions,
        );
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
            assumptions: $this->assumptions,
            paymentOptions: $this->paymentOptions,
        );
    }
}

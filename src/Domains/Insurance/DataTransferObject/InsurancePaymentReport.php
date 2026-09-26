<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class InsurancePaymentReport
{
    public function __construct(
        public readonly string $quoteNumber,
        public readonly string $processorReference,
        public readonly string $authorizationCode,
        public readonly string $isoResponseCode,
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly float $amount,
        public readonly float $tax,
        public readonly ?string $orderReference = null,
        public readonly ?string $transactedAt = null,
        public readonly ?string $cardLastFour = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $retrievalReference = null,
        public readonly ?string $batchNumber = null,
        public readonly ?string $receiptNumber = null,
        public readonly ?string $policyNumber = null,
    ) {
    }

    public function forQuote(string $quoteNumber): self
    {
        return $this->rebind($quoteNumber, null);
    }

    public function forPolicy(string $policyNumber): self
    {
        return $this->rebind($this->quoteNumber, $policyNumber);
    }

    protected function rebind(string $quoteNumber, ?string $policyNumber): self
    {
        return new self(
            quoteNumber: $quoteNumber,
            processorReference: $this->processorReference,
            authorizationCode: $this->authorizationCode,
            isoResponseCode: $this->isoResponseCode,
            responseCode: $this->responseCode,
            responseMessage: $this->responseMessage,
            amount: $this->amount,
            tax: $this->tax,
            orderReference: $this->orderReference,
            transactedAt: $this->transactedAt,
            cardLastFour: $this->cardLastFour,
            cardBrand: $this->cardBrand,
            retrievalReference: $this->retrievalReference,
            batchNumber: $this->batchNumber,
            receiptNumber: $this->receiptNumber,
            policyNumber: $policyNumber,
        );
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use JsonException;
use Spatie\LaravelData\Data;

class TaxContext extends Data
{
    public function __construct(
        public readonly string $appId,
        public readonly TaxAddress $shipTo,
        public readonly ?TaxAddress $shipFrom,
        public readonly array $lines,
        public readonly string $currency,
        public readonly ?string $cartSessionId = null,
        public readonly ?string $reference = null,
        public readonly ?string $exemptionType = null,
        public readonly ?string $customerId = null,
        public readonly string $taxDate = '',
    ) {
    }

    /**
     * @throws JsonException
     */
    public function inputHash(): string
    {
        $data = [
            'appId' => $this->appId,
            'shipTo' => $this->shipTo->toArray(),
            'shipFrom' => $this->shipFrom?->toArray(),
            'currency' => $this->currency,
            'exemptionType' => $this->exemptionType,
            'customerId' => $this->customerId,
            'taxDate' => $this->taxDate,
            'lines' => array_map(fn (TaxableLine $l) => [
                'unitPrice' => $l->unitPrice,
                'quantity' => $l->quantity,
                'isTaxable' => $l->isTaxable,
                'taxCategory' => $l->taxCategory,
                'taxCodeOverride' => $l->taxCodeOverride,
                'isShipping' => $l->isShipping,
            ], $this->lines),
        ];

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}

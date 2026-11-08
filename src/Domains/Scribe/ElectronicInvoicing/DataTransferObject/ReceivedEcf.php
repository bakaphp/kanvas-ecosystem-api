<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Spatie\LaravelData\Data;

/**
 * An e-CF a vendor issued to us, as any provider hands it over (WebPOS pulls the signed XML, Alanube pushes
 * structured data). The vendor RNC + e-NCF pair is unique at DGII, so it is the idempotency key for the bill.
 */
class ReceivedEcf extends Data
{
    /**
     * @param ReceivedEcfLine[] $lines
     */
    public function __construct(
        public readonly string $vendorTaxId,
        public readonly string $vendorName,
        public readonly string $encf,
        public readonly EcfTypeEnum $type,
        public readonly string $issueDate,
        public readonly string $currency,
        public readonly float $exchangeRate,
        public readonly EcfTotals $totals,
        public readonly array $lines,
        public readonly string $provider,
        public readonly ?string $dueDate = null,
        public readonly ?string $securityCode = null,
        public readonly ?string $signedAt = null,
        public readonly ?string $qrUrl = null,
        public readonly ?EcfReference $reference = null,
        public readonly ?string $rawXml = null,
    ) {
    }

    public function externalId(): string
    {
        return $this->vendorTaxId . '-' . $this->encf;
    }
}

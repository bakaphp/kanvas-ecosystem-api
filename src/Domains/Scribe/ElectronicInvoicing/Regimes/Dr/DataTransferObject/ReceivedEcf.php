<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use InvalidArgumentException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalDocumentInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Spatie\LaravelData\Data;

/**
 * An e-CF a vendor issued to us, as any provider hands it over (WebPOS pulls the signed XML, Alanube pushes
 * structured data). The vendor RNC + e-NCF pair is unique at DGII, so it is the idempotency key for the bill.
 */
class ReceivedEcf extends Data implements ReceivedFiscalDocumentInterface
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

    public function country(): string
    {
        return DrFiscalRegime::COUNTRY;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function documentType(): EcfTypeEnum
    {
        return $this->type;
    }

    public function number(): string
    {
        return $this->encf;
    }

    public function vendorTaxId(): string
    {
        return $this->vendorTaxId;
    }

    public function vendorName(): string
    {
        return $this->vendorName;
    }

    public function issueDate(): string
    {
        return $this->issueDate;
    }

    public function dueDate(): ?string
    {
        return $this->dueDate;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function exchangeRate(): float
    {
        return $this->exchangeRate;
    }

    public function totalsSummary(): array
    {
        return $this->totals->toArray();
    }

    /**
     * @return list<ReceivedEcfLine>
     */
    public function lines(): array
    {
        return array_values($this->lines);
    }

    public function rawXml(): ?string
    {
        return $this->rawXml;
    }

    /**
     * The DR row needs DGII's seal, signing time, stamp and references beside the contract's fields.
     */
    public static function narrow(ReceivedFiscalDocumentInterface $received): self
    {
        return $received instanceof self
            ? $received
            : throw new InvalidArgumentException('The DR regime was handed a ' . $received::class . ' received document.');
    }
}

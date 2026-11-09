<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use InvalidArgumentException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Spatie\LaravelData\Data;

/**
 * The DR e-CF payload, built from a Scribe document by EcfDocumentBuilder. DR providers only map it.
 *
 * systemRef is the send attempt's uuid (FiscalDocumentSubmission): the provider dedupes on it, so a status query
 * about an attempt never issues twice and a new attempt is never mistaken for an earlier one.
 * Dates are Y-m-d strings so the DTO stays plain data.
 *
 * purchaseOrderNumber (E45), export (E46) and foreignPayment (E47) are type-specific and null elsewhere.
 */
class EcfDocument extends Data implements FiscalPayloadInterface
{
    /**
     * @param EcfLine[] $lines
     */
    public function __construct(
        public readonly EcfTypeEnum $type,
        public readonly string $encf,
        public readonly string $sequenceExpiresAt,
        public readonly string $issueDate,
        public readonly string $systemRef,
        public readonly EcfParty $buyer,
        public readonly string $currency,
        public readonly float $exchangeRate,
        public readonly bool $isCredit,
        public readonly array $lines,
        public readonly EcfTotals $totals,
        public readonly ?EcfReference $reference = null,
        public readonly ?string $purchaseOrderNumber = null,
        public readonly ?EcfExport $export = null,
        public readonly ?EcfForeignPayment $foreignPayment = null,
    ) {
    }

    /**
     * A DR provider is handed the payload its regime built; anything else is a wiring error.
     */
    public static function narrow(FiscalPayloadInterface $payload): self
    {
        return $payload instanceof self
            ? $payload
            : throw new InvalidArgumentException('A DR e-CF provider was handed a ' . $payload::class . ' payload.');
    }

    public function documentType(): EcfTypeEnum
    {
        return $this->type;
    }

    public function number(): string
    {
        return $this->encf;
    }

    public function issueDate(): string
    {
        return $this->issueDate;
    }

    public function systemRef(): string
    {
        return $this->systemRef;
    }

    public function reference(): ?EcfReference
    {
        return $this->reference;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe\Regime;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;

final readonly class FakePayload implements FiscalPayloadInterface
{
    public function __construct(
        public FakeDocumentTypeEnum $type,
        public string $number,
        public string $issueDate,
        public string $systemRef,
        public float $total,
        public string $currency,
        public ?EcfReference $reference = null,
    ) {
    }

    public function documentType(): FiscalDocumentTypeInterface
    {
        return $this->type;
    }

    public function number(): string
    {
        return $this->number;
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

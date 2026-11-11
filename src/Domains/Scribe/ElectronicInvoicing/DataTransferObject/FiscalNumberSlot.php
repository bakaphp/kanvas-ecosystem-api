<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;

/**
 * A number reserved for one document, then taken. Never queued, so it may hold the type object.
 */
final readonly class FiscalNumberSlot
{
    public function __construct(
        public FiscalDocumentTypeInterface $type,
        public string $number = '',
        public ?string $expiresAt = null,
        public ?int $fiscalNumberRangeId = null,
    ) {
    }

    public function withNumber(string $number, ?int $fiscalNumberRangeId): self
    {
        return new self(
            type: $this->type,
            number: $number,
            expiresAt: $this->expiresAt,
            fiscalNumberRangeId: $fiscalNumberRangeId,
        );
    }
}

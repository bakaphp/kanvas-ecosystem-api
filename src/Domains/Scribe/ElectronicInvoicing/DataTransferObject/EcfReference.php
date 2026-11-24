<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * The document a credit/debit note modifies. modificationCode follows DGII: 1 cancels the original,
 * 3 changes amounts.
 */
class EcfReference extends Data
{
    public function __construct(
        public readonly string $encf,
        public readonly string $issueDate,
        public readonly int $modificationCode,
        public readonly ?string $reason = null,
    ) {
    }
}

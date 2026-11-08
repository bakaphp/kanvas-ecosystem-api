<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use Spatie\LaravelData\Data;

class EcfParty extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $taxId = null,
        public readonly bool $isForeign = false,
        public readonly ?string $address = null,
        public readonly ?string $email = null,
    ) {
    }
}

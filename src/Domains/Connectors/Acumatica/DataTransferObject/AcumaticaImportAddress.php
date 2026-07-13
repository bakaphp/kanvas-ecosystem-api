<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * A party's default address (dbo.Address), normalized off the BAccount join.
 */
class AcumaticaImportAddress extends Data
{
    public function __construct(
        public readonly string $address,
        public readonly ?string $address_2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?string $zip,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {
    }
}

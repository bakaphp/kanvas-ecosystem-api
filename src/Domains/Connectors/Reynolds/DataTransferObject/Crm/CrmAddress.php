<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

class CrmAddress extends Data
{
    public function __construct(
        public readonly string $address,
        public readonly ?string $address_2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $zip = null
    ) {
    }
}

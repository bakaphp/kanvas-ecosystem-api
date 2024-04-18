<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Spatie\LaravelData\Data;

class Address extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly string $address,
        public readonly ?string $address_2 = null,
        public readonly ?string $city = null,
        public readonly ?string $county = null,
        public readonly ?string $state = null,
        public readonly ?string $country = null,
        public readonly ?string $zipcode = null,
        public readonly bool $is_default = true,
        public readonly ?int $city_id = null,
        public readonly ?int $state_id = null,
        public readonly ?int $country_id = null,
    ) {
    }
}

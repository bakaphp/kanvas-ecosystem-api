<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class BillingDetail extends Data
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $address1,
        public readonly string $city,
        public readonly string $administrativeArea,
        public readonly string $postalCode,
        public readonly string $country,
        public readonly string $email,
        public readonly string $phone,
    ) {
    }
}

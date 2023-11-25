<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Spatie\LaravelData\Data;

class CreditCardBilling extends Data
{
    public function __construct(
        public readonly string $address,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zip,
        public readonly string $country = 'USA',
        public readonly ?string $address2 = null
    ) {
    }
}

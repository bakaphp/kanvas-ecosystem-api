<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Spatie\LaravelData\Data;

class CreditCardBilling extends Data
{
    public function __construct(
        public string $address,
        public string $city,
        public string $state,
        public string $zip,
        public string $country = 'USA',
        public ?string $address2 = null
    ) {
    }
}
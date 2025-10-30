<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\DataTransferObject;

use Spatie\LaravelData\Data;

class CustomerDetail extends Data
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email = null,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {
    }
}

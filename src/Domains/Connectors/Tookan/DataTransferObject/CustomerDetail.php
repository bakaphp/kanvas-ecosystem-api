<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\DataTransferObject;

use Spatie\LaravelData\Data;

class CustomerDetail extends Data
{
    public function __construct(
        public string $name,
        public ?string $phone,
        public ?string $address,
        public float|string|null $latitude = null,
        public float|string|null $longitude = null,
        public ?string $email = null,
    ) {
    }
}

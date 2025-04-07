<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Spatie\LaravelData\Data;

class OrderCustomer extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $phone = null,
        public readonly ?string $note = null,
    ) {
    }
}

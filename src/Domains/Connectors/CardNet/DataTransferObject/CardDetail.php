<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\DataTransferObject;

use Spatie\LaravelData\Data;

class CardDetail extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $pan,
        public readonly string $cvv,
        public readonly string $expiration,
        public readonly string $titular,
        public readonly int $customerId,
    ) {
    }

    public function toArray(): array
    {
        return [
            'Email' => $this->email,
            'Pan' => $this->pan,
            'CVV' => $this->cvv,
            'Expiration' => $this->expiration,
            'Titular' => $this->titular,
            'CustomerId' => $this->customerId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\DataTransferObject;

use Baka\Support\Str;
use Spatie\LaravelData\Data;

class ShippingDestination extends Data
{
    public function __construct(
        public readonly string $countryCode,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
    ) {
    }

    public static function forInput(array $input): self
    {
        return new self(
            countryCode: (string) ($input['country'] ?? ''),
            city: Str::trimToNull($input['city'] ?? null),
            postalCode: Str::trimToNull($input['postal_code'] ?? null),
        );
    }

    public function toInput(): array
    {
        return [
            'country' => $this->countryCode,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\DataTransferObject;

use Spatie\LaravelData\Data;

class ShippingQuote extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly string $serviceCode,
        public readonly string $serviceName,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?int $transitMinDays = null,
        public readonly ?int $transitMaxDays = null,
        public readonly array $meta = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\DataTransferObject;

use Spatie\LaravelData\Data;

class ShippingQuote extends Data
{
    public const string FREE = 'free';

    public function __construct(
        public readonly string $provider,
        public readonly string $serviceCode,
        public readonly string $serviceName,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?int $transitMinDays = null,
        public readonly ?int $transitMaxDays = null,
        public readonly array $meta = [],
        public readonly array $bookingMeta = [],
    ) {
    }

    public static function free(string $currency): self
    {
        return new self(
            provider: self::FREE,
            serviceCode: self::FREE,
            serviceName: 'Free shipping',
            amount: 0.0,
            currency: $currency,
        );
    }
}

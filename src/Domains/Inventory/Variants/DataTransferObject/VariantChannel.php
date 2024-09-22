<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\DataTransferObject;

use Spatie\LaravelData\Data;

class VariantChannel extends Data
{
    public function __construct(
        public float $price,
        public float $discounted_price = 0.00,
        public bool $is_published = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            price: (float) ($data['price'] ?? 0.00),
            discounted_price: (float) ($data['discounted_price'] ?? 0.00),
            is_published: (bool) ($data['is_published'] ?? false)
        );
    }
}

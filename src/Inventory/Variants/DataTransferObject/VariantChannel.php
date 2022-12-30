<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\DataTransferObject;

use Spatie\LaravelData\Data;

class VariantChannel extends Data
{
    public function __construct(
        public float $price,
        public float $discounted_price,
        public bool $is_published = false,
    ) {
    }
}

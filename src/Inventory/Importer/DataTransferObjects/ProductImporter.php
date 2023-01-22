<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Importer\DataTransferObjects;

use Spatie\LaravelData\Data;

class ProductImporter extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public string $slug,
        public string $sku,
        public float $price,
        public array $variants,
        public array $categories,
        public bool $isPublished = true,
        public float $discountPrice = 0.0,
        public int $position = 0,
        public ?string $shortDescription = null,
        public ?string $htmlDescription = null,
        public ?string $warrantyTerms = null,
        public ?string $upc = null,
        public ?string $source = null,
        public ?string $sourceId = null,
        public array $productType = [],
        public array $attributes = [],
        public array $variantAttributes = [],
        public array $warehouses = ['default'],
        public array $channels = ['default'],
    ) {
    }

    /**
     * is this product from shopify , bigcommerce or any other source.
     */
    public function isFromThirdParty() : bool
    {
        return $this->source !== null && $this->sourceId !== null;
    }

    public function getSourceKey() : string
    {
        return $this->source . '_id';
    }
}

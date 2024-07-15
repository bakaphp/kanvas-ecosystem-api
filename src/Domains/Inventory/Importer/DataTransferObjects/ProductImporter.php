<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\DataTransferObjects;

use Kanvas\Exceptions\ValidationException;
use Spatie\LaravelData\Data;

class ProductImporter extends Data
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $sku,
        public float $price,
        public array $variants,
        public ?string $description = null,
        public array $categories = [],
        public int $quantity = 0,
        public bool $isPublished = true,
        public float $discountPrice = 0.0,
        public int $position = 0,
        public ?string $shortDescription = null,
        public ?string $htmlDescription = null,
        public ?string $warrantyTerms = null,
        public ?string $upc = null,
        public ?string $source = null,
        public ?string $sourceId = null,
        public array $files = [],
        public array $productType = [],
        public array $attributes = [],
        public array $customFields = [],
        public array $warehouses = [
            [
                'warehouse' => 'default',
                'channel' => 'default',
            ],
        ],
    ) {
    }

    /**
     * @deprecated
     */
    public function isFromThirdParty(): bool
    {
        return $this->source && $this->sourceId;
    }

    public function getSourceKey(): string
    {
        if ($this->source === null) {
            throw new ValidationException('Importer Source is required');
        }

        return $this->source;
    }
}

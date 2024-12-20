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
        public array $variants,
        public ?string $description = null,
        public array $categories = [],
        public bool $isPublished = true,
        public int $position = 0,
        public ?string $shortDescription = null,
        public ?string $htmlDescription = null,
        public ?string $warrantyTerms = null,
        public ?string $upc = null,
        public ?string $source = null,
        public ?string $sourceId = null,
        public array $status = [],
        public array $files = [],
        public array $productType = [],
        public array $attributes = [],
        public array $customFields = []
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

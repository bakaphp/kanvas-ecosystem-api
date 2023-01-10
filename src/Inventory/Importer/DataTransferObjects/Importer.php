<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Importer\DataTransferObjects;

use Spatie\LaravelData\Data;

class Importer extends Data
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $warranty_terms = null,
        public ?string $upc = null,
        public string $source_id,
        public bool $is_published = true,
        public ?array $categories = null,
        public ?array $warehouses = null,
        public ?array $variants = null,
        public ?array $attributes = null,
        public ?array $productType = null,
    ) {
    }
}

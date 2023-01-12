<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\DataTransferObject;

use Spatie\LaravelData\Data;

class Product extends Data
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public ?int $products_types_id = null,
        public string $name,
        public string $description,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $warranty_terms = null,
        public ?string $upc = null,
        public bool $is_published = true,
        public array $categories = [],
        public array $warehouses = [],
        public array $variants = [],
        public array $attributes = [],
        public array $productType = [],
    ) {
    }
}

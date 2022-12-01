<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\DataTransferObject;

class Product
{
    public function __construct(
        public ?int $products_types_id = null,
        public string $name,
        public string $description,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $warranty_terms = null,
        public ?string $upc = null,
        public bool $is_published = true,
    ) {
    }
}

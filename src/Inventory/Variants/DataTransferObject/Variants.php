<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\DataTransferObject;

use Spatie\LaravelData\Data;

class Variants extends Data
{
    public function __construct(
        public int $products_id,
        public string $name,
        public string $description,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $sku = null,
        public ?string $ean = null,
        public ?string $barcode = null,
        public ?string $serial_number = null,
        public bool $is_published = true
    ) {
    }
}

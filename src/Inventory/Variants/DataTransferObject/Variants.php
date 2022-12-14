<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\DataTransferObject;

use Spatie\LaravelData\Data;

class Variants extends Data {

    public function __construct(
        public string $name,
        public string $slug,
        public string $description,
        public ?string $short_description=null,
        public ?string $html_description=null,
        public string $sku,
        public string $ean,
        public string $barcode,
        public ?string $serial_number,
        public bool $is_published
    ){
    }

}
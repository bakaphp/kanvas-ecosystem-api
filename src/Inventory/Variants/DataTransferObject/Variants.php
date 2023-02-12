<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\DataTransferObject;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Spatie\LaravelData\Data;

class Variants extends Data
{
    public function __construct(
        public Products $product,
        public string $name,
        public string $description,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $sku = null,
        public ?string $ean = null,
        public ?string $barcode = null,
        public ?string $serial_number = null,
        public bool $is_published = true,
        public ?string $slug = null
    ) {
    }

    public static function viaRequest(array $request): self
    {
        return new self(
            ProductsRepository::getById($request['products_id'], auth()->user()->getCurrentCompany()),
            $request['name'],
            $request['description'] ?? '',
            $request['short_description'] ?? null,
            $request['html_description'] ?? null,
            $request['sku'] ?? null,
            $request['ean'] ?? null,
            $request['barcode'] ?? null,
            $request['serial_number'] ?? null,
            $request['is_published'] ?? true,
        );
    }
}

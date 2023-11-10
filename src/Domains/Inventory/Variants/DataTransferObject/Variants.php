<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Spatie\LaravelData\Data;

class Variants extends Data
{
    public function __construct(
        public Products $product,
        public string $name,
        public ?int $warehouse_id = null,
        public ?string $description = null,
        public ?int $status_id = null,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $sku = null,
        public ?string $ean = null,
        public ?string $barcode = null,
        public ?string $serial_number = null,
        public ?string $slug = null,
        public array $files = []
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        if($user->isAppOwner()) {
            $product = ProductsRepository::getById($request['products_id']);
        } else {
            $product = ProductsRepository::getById($request['products_id'], $user->getCurrentCompany());
        }

        return new self(
            $product,
            $request['name'],
            isset($request['warehouse']['id']) ? (int) $request['warehouse']['id'] : null,
            $request['description'] ?? null,
            $request['status_id'] ?? null,
            $request['short_description'] ?? null,
            $request['html_description'] ?? null,
            $request['sku'] ?? null,
            $request['ean'] ?? null,
            $request['barcode'] ?? null,
            $request['serial_number'] ?? null,
            $request['slug'] ?? null,
            $request['files'] ?? []
        );
    }
}

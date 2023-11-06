<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Spatie\LaravelData\Data;

class Product extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public CompanyInterface $company,
        public UserInterface $user,
        public string $name,
        public string $description,
        public ?ProductsTypes $productsType = null,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $warranty_terms = null,
        public ?string $upc = null,
        public bool $is_published = true,

        //@var array<int>
        public array $categories = [],
        public array $warehouses = [],
        public array $variants = [],
        public array $attributes = [],
        public array $productType = [],
        public ?string $slug = null,
    ) {
    }

    public static function viaRequest(array $request): self
    {
        if (app()->bound(AppKey::class) && isset($request['company_id'])) {
            $company = Companies::getById($request['company_id']);
        }else {
            $company = auth()->user()->getCurrentCompany();
        }

        return new self(
            app(Apps::class),
            $company,
            auth()->user(),
            $request['name'],
            $request['description'],
            isset($request['products_types_id']) ? ProductsTypesRepository::getById($request['products_types_id'], $company) : null,
            $request['short_description'] ?? null,
            $request['html_description'] ?? null,
            $request['warranty_terms'] ?? null,
            $request['upc'] ?? null,
            $request['is_published'] ?? true,
            $request['categories'] ?? [],
            $request['warehouses'] ?? [],
            $request['variants'] ?? [],
            $request['attributes'] ?? [],
            $request['productType'] ?? [],
            $request['slug'] ?? null,
        );
    }
}

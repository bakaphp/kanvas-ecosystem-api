<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use App\GraphQL\Inventory\Mutations\Variants\Variants;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;
use Throwable;

class CreateProductAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected ProductDto $productDto,
        protected UserInterface $user,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Products
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->productDto->company,
            $this->user
        );

        try {
            DB::connection('inventory')->beginTransaction();

            $productType = $this->productDto?->productsType?->getId();

            $search = [
                'slug' => $this->productDto->slug ?? Str::slug($this->productDto->name),
                'apps_id' => $this->productDto->app->getId(),
                'companies_id' => $this->productDto->company->getId(),
            ];

            $products = Products::updateOrCreate(
                $search,
                [
                    'products_types_id' => $productType,
                    'name' => $this->productDto->name,
                    'description' => $this->productDto->description,
                    'short_description' => $this->productDto->short_description,
                    'html_description' => $this->productDto->html_description,
                    'warranty_terms' => $this->productDto->warranty_terms,
                    'upc' => $this->productDto->upc,
                    'users_id' => $this->user->getId(),
                    'is_published' => $this->productDto->is_published,
                    'published_at' => Carbon::now(),
                ]
            );

            if ($this->productDto->categories) {
                foreach ($this->productDto->categories as $category) {
                    $category = CategoriesRepository::getById($category, $this->productDto->company);
                }

                $products->categories()->attach($this->productDto->categories);
            }

            if ($this->productDto->warehouses) {
                foreach ($this->productDto->warehouses as $warehouse) {
                    WarehouseRepository::getById($warehouse, $this->productDto->company);
                }
                $products->warehouses()->attach($this->productDto->warehouses);
            }

            if($this->productDto->variants) {
                foreach ($this->productDto->variants as $variant) {
                    $variant['products_id'] = $products->getId();
                    $variantData['input'] = $variant;
                    $action = new Variants();
                    $action->create(false, $variantData);
                }
            }

            DB::connection('inventory')->commit();
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        return $products;
    }
}

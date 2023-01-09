<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class CreateProductAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        private ProductDto $productDto
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        $products = Products::create([
            'products_types_id' => $this->productDto->products_types_id,
            'name' => $this->productDto->name,
            'description' => $this->productDto->description,
            'short_description' => $this->productDto->short_description,
            'html_description' => $this->productDto->html_description,
            'warranty_terms' => $this->productDto->warranty_terms,
            'upc' => $this->productDto->upc
        ]);
        if ($this->productDto->categories) {
            foreach ($this->productDto->categories as $category) {
                $category = CategoriesRepository::getById($category);
            }
            $products->categories()->attach($this->productDto->categories);
        }
        if ($this->productDto->warehouses) {
            foreach ($this->productDto->warehouses as $warehouse) {
                WarehouseRepository::getById($warehouse);
            }
            $products->warehouses()->attach($this->productDto->warehouses);
        }
        return $products;
    }
}

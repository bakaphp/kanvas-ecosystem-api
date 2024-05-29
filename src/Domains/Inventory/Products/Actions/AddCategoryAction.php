<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsCategories;

class AddCategoryAction
{
    public function __construct(
        protected Products $product,
        protected Categories $category
    ) {
    }

    public function execute(): void
    {
        ProductsCategories::firstOrCreate(
            [
                'categories_id' => $this->category->getId(),
                'products_id' => $this->product->getId(),
            ]
        );
    }

}
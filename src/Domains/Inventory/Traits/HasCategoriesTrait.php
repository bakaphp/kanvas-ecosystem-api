<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Products\Actions\AddCategoryAction;

trait HasCategoriesTrait
{
    public function categories()
    {
        return $this->morphToMany(
            Categories::class,
            'resource',
            'category_resource_entity',
            'resource_id',
            'categories_id'
        );
    }

    public function addCategory(string $categoryId): void
    {
        $category = CategoriesRepository::getByResource(
            $categoryId,
            static::class
        );

        (new AddCategoryAction(
            $this,
            $category
        ))->execute();
    }
}

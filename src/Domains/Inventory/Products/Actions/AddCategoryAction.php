<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Models\CategoryResourcesEntity;

class AddCategoryAction
{
    public function __construct(
        protected Model $entity,
        protected Categories $category
    ) {
    }

    public function execute(): void
    {
        CategoryResourcesEntity::firstOrCreate(
            [
                'categories_id' => $this->category->getId(),
                'resource_id' => $this->entity->getId(),
                'resource_type' => get_class($this->entity),
            ]
        );
    }
}

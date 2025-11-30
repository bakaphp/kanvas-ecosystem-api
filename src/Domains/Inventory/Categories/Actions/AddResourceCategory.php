<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Actions;

use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Models\CategoryResources;
use Kanvas\SystemModules\Models\SystemModules;

class AddResourceCategory
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected Categories $category,
        protected SystemModules $systemModule
    ) {
    }

    /**
     * execute.
     */
    public function execute(): CategoryResources
    {
        $categoryResource = CategoryResources::firstOrCreate([
            'system_modules_id' => $this->systemModule->getId(),
            'category_id' => $this->category->getId(),
        ]);

        return $categoryResource;
    }
}

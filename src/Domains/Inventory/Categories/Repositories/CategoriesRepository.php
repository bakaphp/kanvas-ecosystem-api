<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\SystemModules\Models\SystemModules;

class CategoriesRepository
{
    use SearchableTrait;

    public static function getModel(): Categories
    {
        return new Categories();
    }

    public static function getByResource(string $categoryId, string $resource): Categories
    {
        $systemModule = SystemModules::where('model_name', $resource)
            ->fromPublicApp()
            ->firstOrFail();

        $systemModuleId = $systemModule->id;

        $category = Categories::query()
            ->fromApp()
            ->fromCompany()
            ->fromResource($systemModuleId)
            ->where('id', $categoryId)
            ->firstOrFail();

        return $category;
    }
}

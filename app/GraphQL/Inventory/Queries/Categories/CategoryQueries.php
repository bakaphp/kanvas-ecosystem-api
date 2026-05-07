<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Queries\Categories;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class CategoryQueries
{
    public function getCategoriesBuilder(mixed $root, array $args): mixed
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($root::class, $app);

        return Categories::whereHas('categoryEntities', function ($query) use ($root, $systemModule) {
            $query->where('entity_id', $root->getKey());
            $query->where('taggable_type', $systemModule->model_name);
            $query->where('apps_id', $systemModule->apps_id);
        })->where('is_deleted', StateEnums::NO->getValue());
    }
}

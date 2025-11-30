<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Categories;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\SystemModules\Models\SystemModules;

class GetAvailableCategories
{
    public function resolve($root, array $args): Builder
    {
        if (empty($args['system_module_id'])) {
            throw new InvalidArgumentException('system_module_id is required');
        }

        $systemModule = SystemModules::where('id', $args['system_module_id'])
                        ->notDeleted()
                        ->fromPublicApp()
                        ->firstOrFail();

        return Categories::query()
            ->fromApp()
            ->fromCompany()
            ->fromResource($systemModule->getId());
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Kanvas\Enums\ModuleEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsTypes;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ModulesRepositories
{
    public static function getAbilitiesByModule(): array
    {
        return [
            ModuleEnum::ECOSYSTEM->value => [
                Apps::class => [
                    'create apps',
                    'edit apps',
                    'delete apps',
                ],
                Companies::class => [
                    'create companies',
                    'edit companies',
                    'delete companies',
                ],
                Users::class => [
                    'create users',
                    'edit users',
                    'delete users',
                    'invite users',
                ],
            ],
            ModuleEnum::INVENTORY->value => [
                Products::class => [
                    'create products',
                    'edit products',
                    'delete products',
                ],
                ProductsTypes::class => [
                    'create products types',
                    'edit products types',
                    'delete products types',
                ],
                Regions::class => [
                    'create regions',
                    'edit regions',
                    'delete regions',
                ],
                Warehouses::class => [
                    'create warehouses',
                    'edit warehouses',
                    'delete warehouses',
                ],
            ],
        ];
    }
}

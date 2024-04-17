<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Kanvas\AccessControlList\Enums\ModuleEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\Users;
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
                    'create',
                    'edit',
                    'delete',
                ],
                Companies::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Users::class => [
                    'create',
                    'edit',
                    'delete',
                    'invite',
                ],
            ],
            ModuleEnum::INVENTORY->value => [
                Products::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                ProductsTypes::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Regions::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Warehouses::class => [
                    'create',
                    'edit',
                    'delete',
                ],
            ],
        ];
    }
}

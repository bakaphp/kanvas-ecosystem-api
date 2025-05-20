<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\ModuleEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;

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
                Channels::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Attributes::class => [
                    'create',
                    'edit',
                    'delete',
                ],
            ],
            ModuleEnum::CRM->value => [
                People::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Lead::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                LeadReceiver::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Rotation::class => [
                    'create',
                    'edit',
                    'delete',
                ],
            ]
        ];
    }

    public static function getAllAbilities(): array
    {
        $abilities = [];
        foreach (self::getAbilitiesByModule() as $module => $systemModule) {
            $abilities = array_merge($abilities, $systemModule);
        }
        return $abilities;
    }
}

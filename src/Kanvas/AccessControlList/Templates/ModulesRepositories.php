<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

use Kanvas\Enums\ModuleEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Rotations\Models\Rotation;
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
                Channels::class => [
                    'create channels',
                    'edit channels',
                    'delete channels',
                ],
                Attributes::class => [
                    'create attributes',
                    'edit attributes',
                    'delete attributes',
                ],
            ],
            ModuleEnum::CRM->value =>[
                People::class => [
                    'create people',
                    'edit people',
                    'delete people',
                ],
                Lead::class => [
                    'create leads',
                    'edit leads',
                    'delete leads',
                ],
                LeadReceiver::class => [
                    'create lead receiver',
                    'edit lead receiver',
                    'delete lead receiver',
                ],
                Rotation::class => [
                    'create rotation',
                    'edit rotation',
                    'delete rotation',
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

<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Lead;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsTypes;
use Kanvas\Users\Models\Users;

class AbilitiesRepositories
{
    public static function getDefaultAbilities()
    {
        return [
            Users::class => [
                'create',
                'read',
                'update',
                'delete',
            ],
            Lead::class => [
                'create',
                'read',
                'update',
                'delete',
            ],
            Companies::class => [
                'create',
                'read',
                'update',
                'delete',
            ],
            Products::class => [
                'create',
                'read',
                'update',
                'delete',
            ],
            ProductsTypes::class => [
                'create',
                'read',
                'update',
                'delete',
            ],

        ];
    }
}

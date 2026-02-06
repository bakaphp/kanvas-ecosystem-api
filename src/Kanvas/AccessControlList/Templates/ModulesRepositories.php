<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

use Kanvas\AccessControlList\Models\ModulePermission;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\ModuleEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class ModulesRepositories
{
    /**
     * Get module-level permissions.
     * These are permissions that control access to entire modules.
     * Returns compound permission names like 'view-module-inventory', 'manage-module-crm'
     */
    public static function getModulePermissions(): array
    {
        $modules = [
            ModuleEnum::ECOSYSTEM,
            ModuleEnum::INVENTORY,
            ModuleEnum::CRM,
            ModuleEnum::SOCIAL,
            ModuleEnum::WORKFLOW,
            ModuleEnum::ACTION_ENGINE,
        ];

        $permissions = [];
        $actions = ['view', 'manage'];

        foreach ($modules as $module) {
            $modulePermissions = [];
            foreach ($actions as $action) {
                $modulePermissions[] = ModulePermission::getPermissionName($module->value, $action);
            }
            $permissions[$module->value] = $modulePermissions;
        }

        return $permissions;
    }

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
                Regions::class => [
                    'create',
                    'edit',
                    'delete',
                ],
            ],
            ModuleEnum::INVENTORY->value => [
                Products::class => [
                    'create',
                    'edit',
                    'delete',
                    'is_published',
                ],
                ProductsTypes::class => [
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
                Status::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Categories::class => [
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
                LeadType::class => [
                    'create',
                    'edit',
                    'delete',
                ],
                Pipeline::class => [
                    'create',
                    'edit',
                    'delete',
                ],
            ],
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

    /**
     * Get the module ID for a given model class name.
     */
    public static function getModuleIdByModelName(string $modelName): ?int
    {
        foreach (self::getAbilitiesByModule() as $moduleId => $models) {
            if (array_key_exists($modelName, $models)) {
                return $moduleId;
            }
        }

        return null;
    }

    /**
     * Get all module permission names as a flat array.
     * Returns: ['view-module-ecosystem', 'manage-module-ecosystem', 'view-module-inventory', ...]
     */
    public static function getAllModulePermissionNames(): array
    {
        $allPermissions = [];
        foreach (self::getModulePermissions() as $moduleId => $permissions) {
            $allPermissions = array_merge($allPermissions, $permissions);
        }

        return $allPermissions;
    }
}

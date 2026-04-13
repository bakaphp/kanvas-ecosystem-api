<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

use Kanvas\AccessControlList\Models\ModulePermission;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\ModuleEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Intelligence\Agents\Models\Agent as AIAgent;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Souk\Orders\Models\Order;
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
            ModuleEnum::AI,
            ModuleEnum::COMMERCE,
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
        $crud = [
            'view',
            'create',
            'edit',
            'delete',
        ];

        $moduleModels = [
            ModuleEnum::ECOSYSTEM->value => [
                Apps::class => $crud,
                Companies::class => $crud,
                Users::class => [...$crud, 'invite'],
                Regions::class => $crud,
            ],
            ModuleEnum::INVENTORY->value => [
                Products::class => [...$crud, 'is_published'],
                ProductsTypes::class => $crud,
                Variants::class => $crud,
                Warehouses::class => $crud,
                Channels::class => $crud,
                Attributes::class => $crud,
                Status::class => $crud,
                Categories::class => $crud,
            ],
            ModuleEnum::CRM->value => [
                People::class => $crud,
                Lead::class => $crud,
                LeadReceiver::class => $crud,
                Rotation::class => $crud,
                LeadType::class => $crud,
                Pipeline::class => $crud,
                Agent::class => $crud,
                Organization::class => $crud,
            ],
            ModuleEnum::SOCIAL->value => [
                Channel::class => $crud,
                Message::class => $crud,
                Tag::class => $crud,
            ],
            ModuleEnum::ACTION_ENGINE->value => [
                CompanyAction::class => $crud,
                Engagement::class => $crud,
                TaskList::class => $crud,
                TaskListItem::class => $crud,
            ],
            ModuleEnum::AI->value => [
                AIAgent::class => $crud,
            ],
            ModuleEnum::COMMERCE->value => [
                Order::class => $crud,
            ],
        ];

        return $moduleModels;
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

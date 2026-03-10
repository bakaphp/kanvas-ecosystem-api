<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Kanvas\Enums\ModuleEnum;
use Kanvas\Models\BaseModel;

/**
 * ModulePermission Model.
 * This is a virtual model used to represent module-level permissions.
 * Uses compound permission names (e.g., view-module-inventory) instead of entity_type
 * to avoid Bouncer trying to resolve classes.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class ModulePermission extends BaseModel
{
    protected $table = 'kanvas_modules';

    public const MODULE_PREFIX = 'module-';

    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Get the compound permission name for a module action.
     * Example: getPermissionName(2, 'view') returns 'view-module-inventory'
     */
    public static function getPermissionName(int $moduleId, string $action): string
    {
        $moduleName = self::getModuleName($moduleId);

        return $action . '-' . self::MODULE_PREFIX . strtolower($moduleName);
    }

    /**
     * Get module name from ModuleEnum.
     */
    public static function getModuleName(int $moduleId): string
    {
        $module = ModuleEnum::tryFrom($moduleId);

        return $module ? $module->name : (string) $moduleId;
    }

    /**
     * Parse a compound permission name to get module ID and action.
     * Example: 'view-module-inventory' returns ['moduleId' => 2, 'action' => 'view']
     */
    public static function parsePermissionName(string $permissionName): ?array
    {
        // Pattern: {action}-module-{moduleName}
        if (! preg_match('/^(\w+)-' . self::MODULE_PREFIX . '(\w+)$/', $permissionName, $matches)) {
            return null;
        }

        $action = $matches[1];
        $moduleName = strtoupper($matches[2]);

        foreach (ModuleEnum::cases() as $module) {
            if ($module->name === $moduleName) {
                return [
                    'moduleId' => $module->value,
                    'action' => $action,
                ];
            }
        }

        return null;
    }

    /**
     * Check if a permission name is a module permission.
     */
    public static function isModulePermission(string $permissionName): bool
    {
        return str_contains($permissionName, '-' . self::MODULE_PREFIX);
    }
}

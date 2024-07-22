<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Enums;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;

enum RolesEnums: string
{
    case OWNER = 'Owner';
    case ADMIN = 'Admin';
    case USER = 'Users';
    case AGENT = 'Agents';
    case DEVELOPER = 'Developer';
    case MANAGER = 'Managers';

    case KEY_MAP = "roles:abilities";

    /**
     * Roles are scoped by app
     * in the future companies may create there own roles
     */
    public static function getScope(Apps $app, ?Companies $company = null): string
    {
        $companyId = $company ? $company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();

        return 'app_' . $app->getKey() . '_company_' . $companyId;
    }

    public static function getRoleBySlug(string $slug): string
    {
        $role = match (strtolower($slug)) {
            'owner' => self::OWNER,
            'admin' => self::ADMIN,
            'user' => self::USER,
            'agent' => self::AGENT,
            'developer' => self::DEVELOPER,
            'manager' => self::MANAGER,
            default => self::ADMIN
        };

        return $role->value;
    }

    public static function isEnumValue(string $value): bool
    {
        $values = [
            self::ADMIN->value,
            self::OWNER->value,
            self::USER->value,
            self::AGENT->value,
            self::DEVELOPER->value,
            self::MANAGER->value,
        ];

        return in_array($value, $values);
    }
}

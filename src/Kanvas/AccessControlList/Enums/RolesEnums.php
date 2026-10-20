<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Enums;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;

enum RolesEnums: string
{
    case OWNER = 'Owner';
    case ADMIN = 'Admin';
    case USER = 'Users';
    case AGENT = 'Agents';
    case AGENT_REPORT = 'AgentReport';
    case ENGAGEMENT_USAGE_REPORT = 'EngagementUsageReport';
    case DEVELOPER = 'Developer';
    case MANAGER = 'Managers';
    case INVENTORY_MANAGER = 'InventoryManager';
    case INSURANCE_CLIENT = 'InsuranceClient';
    case NOTIFICATION_MANAGER = 'NotificationAllEngagement';

    case KEY_MAP = 'roles:abilities';

    /**
     * Roles are scoped by app
     * in the future companies may create there own roles
     */
    public static function getScope(AppInterface $app, ?Companies $company = null, bool $global = false): string
    {
        $companyId = $global ? 0 : ($company ? $company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue());

        return 'app_' . $app->getId() . '_company_' . $companyId;
    }

    /**
     * An unknown slug is the least privileged role: a typo in an app's default_signup_role must not
     * make every signup an admin.
     */
    public static function getRoleBySlug(string $slug): string
    {
        $role = match (strtolower($slug)) {
            'owner' => self::OWNER,
            'admin', 'admins' => self::ADMIN,
            'user', 'users' => self::USER,
            'agent', 'agents' => self::AGENT,
            'agentreport', 'agent_report' => self::AGENT_REPORT,
            'engagementusagereport', 'engagement_usage_report' => self::ENGAGEMENT_USAGE_REPORT,
            'developer', 'developers' => self::DEVELOPER,
            'manager', 'managers' => self::MANAGER,
            'inventorymanager', 'inventory_manager' => self::INVENTORY_MANAGER,
            default => self::USER
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
            self::AGENT_REPORT->value,
            self::ENGAGEMENT_USAGE_REPORT->value,
            self::DEVELOPER->value,
            self::MANAGER->value,
            self::INVENTORY_MANAGER->value,
        ];

        return in_array($value, $values);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Enums;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;

enum RolesEnums: string
{
    case ADMIN = 'Admins';
    case USER = 'Users';
    case AGENT = 'Agents';
    case DEVELOPER = 'Developer';

    /**
     * Roles are scoped by app
     * in the future companies may create there own roles
     */
    public static function getScope(Apps $app, ?Companies $company = null): string
    {
        $companyId = $company ? $company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();

        return 'app_' . $app->getKey() . '_company_' . $companyId;
    }
}

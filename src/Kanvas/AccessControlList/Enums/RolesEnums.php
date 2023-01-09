<?php
declare(strict_types=1);

namespace Kanvas\AccessControlList\Enums;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;

enum RolesEnums : Int
{
    case ADMIN = 1;

    /**
     * Get role Key.
     *
     * @param Apps $app
     * @param Companies|null $company
     *
     * @return string
     */
    public static function getKey(Apps $app, ?Companies $company = null) : string
    {
        $companyId = $company ? $company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();

        return 'app_' . $app->getKey() . '_company_' . $companyId;
    }
}

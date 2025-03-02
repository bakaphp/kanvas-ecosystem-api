<?php

declare(strict_types=1);

namespace Kanvas\Souk\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Users\Models\UserCompanyApps;

class B2BConfigurationService
{
    public static function getConfiguredB2BCompany(AppInterface $app, Companies|CompanyInterface $company): Companies|CompanyInterface
    {
        if ($app->get(ConfigurationEnum::USE_B2B_COMPANY_GROUP->value)) {
            $b2bGlobalCompanyId = $app->get(ConfigurationEnum::B2B_GLOBAL_COMPANY->value);
            $userCompanyApp = UserCompanyApps::where('companies_id', $b2bGlobalCompanyId)
                             ->where('apps_id', $app->getId())
                             ->first();
            if ($userCompanyApp) {
                $company = Companies::getById($b2bGlobalCompanyId);
            }
        }

        return $company;
    }

    public static function hasGlobalCompany(
        AppInterface $app,
        string $groupName = 'USE_B2B_COMPANY_GROUP',
        string $companyIdKey = 'B2B_GLOBAL_COMPANY'
    ): bool {
        if ($app->get($groupName)) {
            if (UserCompanyApps::where('companies_id', $app->get($companyIdKey))->where('apps_id', $app->getId())->first()) {
                return true;
            }
        }

        return false;
    }
}

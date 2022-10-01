<?php

declare(strict_types=1);

namespace Kanvas\Companies\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

class CompaniesRepository
{
    /**
     * Get company by Id.
     *
     * @param int $id
     *
     * @return Companies
     *
     * @throws Exception
     */
    public static function getById(int $id) : Companies
    {
        return Companies::where('id', $id)->firstOrFail();
    }

    /**
     * User belongs / has permission in this company.
     *
     * @param Companies $company
     * @param Users $user
     *
     * @throws Exception
     *
     * @return UsersAssociatedCompanies
     */
    public static function userAssociatedToCompany(Companies $company, Users $user) : UsersAssociatedCompanies
    {
        return UsersAssociatedCompanies::where('users_id', $user->getKey())
                                ->where('companies_id', $company->getKey())
                                ->where('is_deleted', StateEnums::NO->getValue())
                                ->firstOrFail();
    }

    /**
     * User associated to this company on the current app.
     *
     * @param Apps $app
     * @param Companies $company
     * @param Users $user
     *
     * @throws Exception
     *
     * @return UsersAssociatedApps
     */
    public static function userAssociatedToCompanyInThisApp(Apps $app, Companies $company, Users $user) : UsersAssociatedApps
    {
        return UsersAssociatedApps::where('users_id', $user->getKey())
                                    ->where('apps_id', $app->getKey())
                                    ->where('companies_id', $company->getKey())
                                    ->where('is_deleted', StateEnums::NO->getValue())
                                    ->firstOrFail();
    }
}

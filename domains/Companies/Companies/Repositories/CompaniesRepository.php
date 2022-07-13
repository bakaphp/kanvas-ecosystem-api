<?php

declare(strict_types=1);

namespace Kanvas\Companies\Companies\Repositories;

use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Branches\Models\CompaniesBranches;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Users\CompanyApps\Models\UserCompanyApps;

class CompaniesRepository
{
    /**
     * Get the default company group for this company on the current app.
     *
     * @param Companies $company
     *
     * @return CompaniesGroups
     */
    public static function getDefaultCompanyGroup(Companies $company) : CompaniesGroups
    {
        $companyGroup = $company->groups->where('companies_groups->is_default', 1)->first();

        if (!$companyGroup) {
            throw new InternalServerErrorException('No default Company Group for Company - ' . $company->id);
        }

        return $companyGroup;
    }

    /**
     * Create a branch for this company.
     *
     * @param string|null $name
     *
     * @return CompaniesBranches
     *
     * @todo Need to create a findFirstOrCreate function later.
     */
    public static function createBranch(Companies $company, ?string $name = null) : CompaniesBranches
    {
        $companyBranch = CompaniesBranches::where('companies_id', $company->getKey())
                        ->where('users_id', $company->user->getKey())
                        ->where('name', empty($name) ? $company->name : $name)->first();

        if (!$companyBranch) {
            $companyBranch = new CompaniesBranches();
            $companyBranch->companies_id = $company->getKey() ;
            $companyBranch->users_id = $company->user->getKey() ;
            $companyBranch->name = empty($name) ? $company->name : $name;
            $companyBranch->save();
        }

        return $companyBranch;
    }

    /**
     * Register this company to the the following app.
     *
     * @param Apps $app
     *
     * @return bool
     */
    public static function registerInApp(Companies $company, Apps $app) : bool
    {
        $companyApps = new UserCompanyApps();
        $companyApps->companies_id = $company->getKey();
        $companyApps->apps_id = $app->getKey();
        $companyApps->created_at = date('Y-m-d H:i:s');
        $companyApps->is_deleted = 0;

        return $companyApps->save();
    }
}

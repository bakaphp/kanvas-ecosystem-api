<?php

declare(strict_types=1);

namespace Kanvas\Companies\Groups\Repositories;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesAssociations;
use Kanvas\Companies\Models\CompaniesGroups;

class CompaniesGroupsRepository
{
    /**
     * Associate a company to this company Group.
     *
     * @param Companies $company
     * @param int $isDefault
     *
     * @return CompaniesAssociations
     */
    public static function associate(CompaniesGroups $companyGroup, Companies $company, int $isDefault = 1) : CompaniesAssociations
    {
        $companiesAssoc = new CompaniesAssociations();
        $companiesAssoc->companies_id = $company->getKey();
        $companiesAssoc->companies_groups_id = $companyGroup->getKey();
        $companiesAssoc->is_default = $isDefault;
        $companiesAssoc->save();

        return $companiesAssoc;
    }
}

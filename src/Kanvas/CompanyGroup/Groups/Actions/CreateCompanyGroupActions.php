<?php

declare(strict_types=1);

namespace Kanvas\CompanyGroup\Groups\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\CompanyGroup\Groups\Models\CompaniesGroups;
use Kanvas\CompanyGroup\Branches\Models\CompaniesBranches;
use Kanvas\Companies\Models\Companies;

class CreateCompanyGroupActions
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Companies $company,
        protected Apps $app
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Companies
     */
    public function execute(string $name, int $isDefault) : CompaniesGroups
    {
        $companyGroup = new CompaniesGroups();
        $companyGroup->name = $name;
        $companyGroup->users_id = $this->company->users_id;
        $companyGroup->apps_id = $this->app->id;
        $companyGroup->is_default = $isDefault;
        $companyGroup->saveOrFail();

        $companyGroup->associate($this->company, $isDefault);

        return $companyGroup;
    }
}

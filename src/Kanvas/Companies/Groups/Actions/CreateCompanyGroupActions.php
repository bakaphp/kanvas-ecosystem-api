<?php

declare(strict_types=1);

namespace Kanvas\Companies\Groups\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesGroups;

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
        $companyGroup = CompaniesGroups::firstOrCreate([
            'apps_id' => $this->app->getKey(),
            'users_id' => $this->company->users_id,
            'is_deleted' => 0
        ], [
            'name' => $name,
            'users_id' => $this->company->users_id,
            'apps_id' => $this->app->getKey(),
            'is_default' => $isDefault,

        ]);

        //print_r($companyGroup->whereRelation('companiesAssoc', 'is_default', '=', 1)->get()->toArray()); die();
        $companyGroup->associate(
            $this->company,
            $isDefault
        );

        return $companyGroup;
    }
}

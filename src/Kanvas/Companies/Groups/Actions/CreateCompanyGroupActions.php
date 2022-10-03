<?php

declare(strict_types=1);

namespace Kanvas\Companies\Groups\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesGroups;
use Kanvas\Enums\StateEnums;

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

        $companyGroup->associate(
            $this->company,
            (int) $companyGroup->companiesAssoc()->count() === 0 ? $isDefault : StateEnums::NO->getValue()
        );

        return $companyGroup;
    }
}

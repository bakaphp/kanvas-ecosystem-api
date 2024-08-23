<?php

declare(strict_types=1);

namespace Kanvas\Dashboard\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Dashboard\Repositories\DashboardRepositories;
use Kanvas\Dashboard\Enums\DashboardEnum;

class SetDefaultDashboardFieldAction
{
    public function __construct(
        public Companies $company,
    ) {
    }

    public function execute()
    {
        if ($fields = $this->company->get(DashboardEnum::DEFAULT_ENUM->value)) {
            $defaultFields = DashboardRepositories::getDefaultFields();
            $fields = array_merge($defaultFields, $fields);
            $this->company->set(DashboardEnum::DEFAULT_ENUM->value, $fields);
        } else {
            $defaultFields = DashboardRepositories::getDefaultFields();
            $this->company->set(DashboardEnum::DEFAULT_ENUM->value, $defaultFields);
        }
    }
}

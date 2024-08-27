<?php

declare(strict_types=1);

namespace Kanvas\Dashboard\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Dashboard\Repositories\DashboardRepositories;
use Kanvas\Dashboard\Enums\DashboardEnum;

class SetDefaultDashboardFieldAction
{
    public function __construct(
        private Companies $company,
        private ?string $field = null,
        private ?string $value = null
    ) {
    }

    public function execute(): void
    {
        $defaultEnumValue = DashboardEnum::DEFAULT_ENUM->value;
        $fields = $this->company->get($defaultEnumValue) ?? [];

        if (! is_null($this->field)) {
            $fields[$this->field] = $this->value;
            $this->company->set($defaultEnumValue, $fields);
        }

        $defaultFields = DashboardRepositories::getDefaultFields();
        $mergedFields = array_merge($defaultFields, $fields);

        $this->company->set($defaultEnumValue, $mergedFields);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Actions;

use Kanvas\CustomFields\DataTransferObject\CustomField;
use Kanvas\CustomFields\Models\CustomFields;

class CreateCustomFieldAction
{
    public function __construct(
        public CustomField $customField
    ) {
    }

    public function execute(): CustomFields
    {
        return CustomFields::firstOrCreate([
            'apps_id' => $this->customField->app->getId(),
            'companies_id' => $this->customField->companies->getId(),
            'users_id' => $this->customField->user->getId(),
            'custom_fields_modules_id' => $this->customField->customFieldsModules->getId(),
            'fields_type_id' => $this->customField->customFieldsTypes->getId(),
            'name' => $this->customField->name,
            'label' => $this->customField->label,
        ]);
    }
}

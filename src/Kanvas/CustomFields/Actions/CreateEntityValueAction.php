<?php
declare(strict_types=1);

namespace Kanvas\CustomFields\Actions;

use Kanvas\CustomFields\DataTransferObject\CustomFieldEntityValue as CustomFieldEntityValueDTO;
use Kanvas\CustomFields\Models\CustomFieldEntityValue;

class CreateEntityValueAction
{

    public function __construct(
        public CustomFieldEntityValueDTO $customFieldEntityValue
    ) {
    }

    public function execute(): CustomFieldEntityValue
    {
        return CustomFieldEntityValue::firstOrCreate([
            'apps_id' => $this->customFieldEntityValue->app->getId(),
            'companies_id' => $this->customFieldEntityValue->companies->getId(),
            'users_id' => $this->customFieldEntityValue->users->getId(),
            'system_modules_id' => $this->customFieldEntityValue->customFieldsModules->system_modules_id,
            'custom_modules_id' => $this->customFieldEntityValue->customFields->getId(),
            'custom_fields_modules_id' => $this->customFieldEntityValue->customFieldsModules->getId(),
            'entity_id' => $this->customFieldEntityValue->entity_id,
            'value' => $this->customFieldEntityValue->value
        ]);
    }

}

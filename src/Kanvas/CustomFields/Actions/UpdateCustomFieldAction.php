<?php
declare(strict_types= 1);

namespace Kanvas\CustomFields\Actions;

use Kanvas\CustomFields\DataTransferObject\CustomField;
use Kanvas\CustomFields\Models\CustomFields;

class UpdateCustomFieldAction
{

    public function __construct(
        protected CustomField $customField,
        protected CustomFields $customFieldModel
    ) {
        
    }

    public function execute(): CustomFields
    {
        $this->customFieldModel->update([
            'custom_fields_modules_id' => $this->customField->customFieldsModules->getId(),
            'fields_type_id' => $this->customField->customFieldsTypes->getId(),
            'name' => $this->customField->name,
            'label' => $this->customField->label,
        ]);
        return $this->customFieldModel;
    }
}

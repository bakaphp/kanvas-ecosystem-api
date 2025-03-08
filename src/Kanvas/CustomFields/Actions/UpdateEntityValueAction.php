<?php
declare(strict_types=1);

namespace Kanvas\CustomFields\Actions;

use Kanvas\CustomFields\DataTransferObject\CustomField;
use Kanvas\CustomFields\Models\CustomFieldEntityValue;
use Kanvas\CustomFields\DataTransferObject\CustomFieldEntityValue as CustomFieldEntityValueDTO;

class UpdateEntityValueAction
{

    public function __construct(
        protected CustomFieldEntityValueDTO $dto
    ) {
    }

    public function execute(): CustomFieldEntityValue
    {
        return CustomFieldEntityValue::updateOrCreate(
            [
            'custom_fields_id' => $this->dto->customFields->getId(),
            'entity_id' => $this->dto->entity_id,
        ],
            [
            'value' => $this->dto->value
        ]
        );
    }
}

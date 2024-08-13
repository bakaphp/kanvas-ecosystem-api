<?php
declare(strict_types=1);

namespace Kanvas\CustomFields\Observers;

use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CustomFieldObserver
{
    public function created(CustomFields $customField): void
    {
        $customField->fireWorkflow(WorkflowEnum::CREATE_CUSTOM_FIELD->value);
    }
}

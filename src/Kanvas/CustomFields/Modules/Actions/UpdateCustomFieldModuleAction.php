<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Modules\Actions;

use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\CustomFields\Modules\DataTransferObject\CustomFieldModule;

class UpdateCustomFieldModuleAction
{
    public function __construct(
        public string $id,
        public CustomFieldModule $customFieldModule,
    ) {
    }

    public function execute(): CustomFieldsModules
    {
        $customFieldsModules = CustomFieldsModules::getById(
            $this->id,
            $this->customFieldModule->app
        );
        $data = [
            'apps_id' => $this->customFieldModule->app->getId(),
            'system_modules_id' => $this->customFieldModule->systemModules ?
                $this->customFieldModule->systemModules->getId() : null,
            'name' => $this->customFieldModule->systemModules ?
                $this->customFieldModule->systemModules->name : $this->customFieldModule->name,
            'model_name' => $this->customFieldModule->systemModules ?
                $this->customFieldModule->systemModules->model_name : $this->customFieldModule->model_name,
        ];
        $customFieldsModules->update($data);

        return $customFieldsModules;
    }
}

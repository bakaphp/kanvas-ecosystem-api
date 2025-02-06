<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Modules\Actions;

use Kanvas\CustomFields\Modules\DataTransferObject\CustomFieldModule;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class CreateCustomFieldModuleAction
{
    public function __construct(
        public CustomFieldModule $customFieldModule,
    ) {
    }

    public function execute(): CustomFieldsModules
    {
        $data = [
            'apps_id' => $this->customFieldModule->app->getId(),
            'system_modules_id' => $this->customFieldModule->systemModules ?
                $this->customFieldModule->systemModules->getId() : null,
            'name' => $this->customFieldModule->name,
            'model_name' => $this->customFieldModule->model_name
        ];
        return CustomFieldsModules::firstOrCreate($data);
    }
}

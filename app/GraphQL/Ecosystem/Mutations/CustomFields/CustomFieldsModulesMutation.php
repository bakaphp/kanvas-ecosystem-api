<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\CustomFields\Modules\Actions\CreateCustomFieldModuleAction;
use Kanvas\CustomFields\Modules\Actions\UpdateCustomFieldModuleAction;
use Kanvas\CustomFields\Modules\DataTransferObject\CustomFieldModule;
use Kanvas\SystemModules\Models\SystemModules;

class CustomFieldsModulesMutation
{
    public function create(mixed $root, array $request): CustomFieldsModules
    {
        $app = app(Apps::class);
        if (isset($request['input']['system_module_uuid'])) {
            $systemModules = SystemModules::getByUuid($request['input']['system_module_uuid'], $app);
        } else {
            $systemModules = null;
        }
        $dto = CustomFieldModule::from([
            'app' => $app,
            'name' => $request['input']['name'],
            'model_name' => $request['input']['model_name'],
            'systemModules' => $systemModules,
        ]);

        return (new CreateCustomFieldModuleAction($dto))->execute();
    }

    public function update(mixed $root, array $request): CustomFieldsModules
    {
        $app = app(Apps::class);
        if (isset($request['input']['system_module_uuid'])) {
            $systemModules = SystemModules::getByUuid($request['input']['system_module_uuid'], $app);
        } else {
            $systemModules = null;
        }
        $dto = CustomFieldModule::from([
            'app' => $app,
            'name' => $request['input']['name'],
            'model_name' => $request['input']['model_name'],
            'systemModules' => $systemModules,
        ]);

        return (new UpdateCustomFieldModuleAction($request['id'], $dto))->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $customFieldModule = CustomFieldsModules::getById($request['id']);

        return $customFieldModule->delete();
    }
}

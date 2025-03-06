<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Actions\CreateCustomFieldAction;
use Kanvas\CustomFields\Actions\UpdateCustomFieldAction;
use Kanvas\CustomFields\DataTransferObject\CustomField;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\CustomFields\Models\CustomFieldsTypes;
use Kanvas\CustomFields\DataTransferObject\CustomFieldEntityValue as CustomFieldEntityValueDto;
use Kanvas\CustomFields\Actions\AssignCustomFieldEntityValue;
use Kanvas\CustomFields\Models\CustomFieldEntityValue;
use Kanvas\SystemModules\Models\SystemModules;

class CustomFieldManagementMutation
{
    public function create(mixed $root, array $req): CustomFields
    {
        $input = $req['input'];
        $app = app(Apps::class);
        $companies = auth()->user()->getCurrentCompany();
        $user = auth()->user();
        $systemModules = SystemModules::getByUuid(
            $input['system_module_uuid'],
            $app
        );
        $customFieldModules = CustomFieldsModules::where(
            'system_modules_id',
            $systemModules->getId()
        )->first();
        $customFieldsTypes = CustomFieldsTypes::getById(
            $input['field_type_id']
        );
        $customFieldDto = CustomField::from([
            'app' => $app,
            'companies' => $companies,
            'users' => $user,
            'customFieldsModules' => $customFieldModules,
            'customFieldsTypes' => $customFieldsTypes,
            'name' => $input['name'],
            'label' => $input['label'] ?? null,
        ]);

        return (new CreateCustomFieldAction($customFieldDto))->execute();
    }

    public function update(mixed $root, array $req): CustomFields
    {
        $input = $req['input'];
        $app = app(Apps::class);
        $companies = auth()->user()->getCurrentCompany();
        $user = auth()->user();
        $customFieldModules = CustomFieldsModules::getById(
            $input['custom_fields_modules_id'],
            $app
        );
        $customFieldsTypes = CustomFieldsTypes::getById(
            $input['field_type_id']
        );
        $customFieldDto = CustomField::from([
            'app' => $app,
            'companies' => $companies,
            'users' => $user,
            'customFieldsModules' => $customFieldModules,
            'customFieldsTypes' => $customFieldsTypes,
            'name' => $input['name'],
            'label' => $input['label'] ?? null,
        ]);
        $customField = CustomFields::getByIdFromCompanyApp($req['id'], $companies, $app);
        return (new UpdateCustomFieldAction($customFieldDto, $customField))->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $customField = CustomFields::getByIdFromCompanyApp(
            $req['id'],
            $company,
            $app
        );

        return $customField->delete();
    }
}

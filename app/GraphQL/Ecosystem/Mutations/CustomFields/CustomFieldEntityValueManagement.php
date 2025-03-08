<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\CustomFields;

use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\CustomFields\DataTransferObject\CustomFieldEntityValue as CustomFieldEntityValueDTO;
use Kanvas\CustomFields\Actions\CreateEntityValueAction;
use Kanvas\CustomFields\Actions\UpdateEntityValueAction;
use Kanvas\CustomFields\Models\CustomFieldEntityValue;

class CustomFieldEntityValueManagement
{

    public function setEntityValue(mixed $root, array $req): CustomFieldEntityValue
    {
        
        $app = app(Apps::class);
        $companies = auth()->user()->getCurrentCompany();
        $user = auth()->user();
        $customField = CustomFields::getByIdFromCompanyApp($req['input']['custom_field_id'], $companies, $app);
        $dto = CustomFieldEntityValueDTO::from([
            'app' => $app,
            'companies' => $companies,
            'users' => $user,
            'customFields' => $customField,
            'entity_id' => $req['input']['entity_id'],
            'value' => $req['input']['value']
        ]);
        return (new CreateEntityValueAction($dto))->execute();
    }

    public function updateEntityValue(mixed $root, array $req): CustomFieldEntityValue
    {
        $app = app(Apps::class);
        $companies = auth()->user()->getCurrentCompany();
        $user = auth()->user();
        $customField = CustomFields::getByIdFromCompanyApp($req['input']['custom_field_id'], $companies, $app);
        $dto = CustomFieldEntityValueDTO::from([
            'app' => $app,
            'companies' => $companies,
            'users' => $user,
            'customFields' => $customField,
            'entity_id' => $req['input']['entity_id'],
            'value' => $req['input']['value']
        ]);
        return (new UpdateEntityValueAction($dto))->execute();
    }
}

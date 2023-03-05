<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

class CustomFieldsRepository
{
    /**
     * Get the entity from the input
     *
     * @param CustomFieldInput $customFieldInput
     * @return Model
     */
    public static function getEntityFromInput(CustomFieldInput $customFieldInput): Model
    {
        $systemModule = SystemModules::where('uuid', $customFieldInput->systemModuleUuid)
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();

        /**
        * @var BaseModel
        */
        $entityModel = (new ($systemModule->model_name));
        $hasUuid = $entityModel->hasColumn('uuid');
        $hasAppId = $entityModel->hasColumn('apps_id');
        $hasCompanyId = $entityModel->hasColumn('companies_id');

        if (! $hasAppId && ! $hasCompanyId) {
            throw new InternalServerErrorException('This system module doesn\'t allow external custom fields');
        }

        if ($hasUuid) {
            $entity = $entityModel::where('uuid', $customFieldInput->entityId)
                    ->fromApp()
                    ->fromCompany(auth()->user()->getCurrentCompany())
                    ->notDeleted()
                    ->firstOrFail();
        } else {
            $entity = $entityModel::where('id', $customFieldInput->entityId)
                    ->fromApp()
                    ->fromCompany(auth()->user()->getCurrentCompany())
                    ->notDeleted()
                    ->firstOrFail();
        }

        return $entity;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

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

        $isUser = $entityModel instanceof Users;
        $isCompany = $entityModel instanceof Companies;

        $hasAppId = $entityModel->hasColumn('apps_id');
        $hasCompanyId = $entityModel->hasColumn('companies_id');

        if (! $hasAppId && ! $hasCompanyId && (! $isUser && ! $isCompany)) {
            throw new InternalServerErrorException('This system module doesn\'t allow external custom fields');
        }

        if ($isUser || $isCompany) {
            $entity = $entityModel::where('uuid', $customFieldInput->entityId)
                    ->notDeleted()
                    ->firstOrFail();

            //check if the user belongs to the company
            if ($entity instanceof Users) {
                UsersRepository::belongsToCompany(
                    $entity,
                    auth()->user()->getCurrentCompany()
                );
            } elseif ($entity instanceof Companies) {
                UsersRepository::belongsToCompany(
                    auth()->user(),
                    $entity
                );
            }
        } else {
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
        }

        return $entity;
    }
}

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
    public static function getEntityFromInput(CustomFieldInput $customFieldInput, Users $user): Model
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
        $field = $hasUuid ? 'uuid' : 'id';

        if ($isUser || $isCompany) {
            $entity = $entityModel::where('uuid', $customFieldInput->entityId)
                    ->notDeleted()
                    ->firstOrFail();

            if ($user->isAppOwner()) {
                return $entity;
            }

            //check if the user belongs to the company
            if ($entity instanceof Users) {
                UsersRepository::belongsToCompany(
                    $entity,
                    $user->getCurrentCompany()
                );
            } elseif ($entity instanceof Companies) {
                UsersRepository::belongsToCompany(
                    $user,
                    $entity
                );
            }
        } else {
            if ($user->isAppOwner()) {
                $entity = $entityModel::where($field, $customFieldInput->entityId)
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
            } else {
                $entity = $entityModel::where($field, $customFieldInput->entityId)
                        ->fromApp()
                        ->fromCompany($user->getCurrentCompany())
                        ->notDeleted()
                        ->firstOrFail();
            }
        }
        return $entity;
    }
}

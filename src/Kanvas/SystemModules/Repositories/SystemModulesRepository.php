<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\SystemModules\Contracts\SystemModuleInputInterface;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Ramsey\Uuid\Uuid;

class SystemModulesRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new SystemModules();
    }

    /**
     * Get System Module by its model_name.
     *
     * @param string $model_name
     */
    public static function getByModelName(string $modelName, ?AppInterface $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        //this sucks but we need to find the solution
        if ($modelName === UserFullTableName::class) {
            $modelName = Users::class;
        }

        return SystemModules::firstOrCreate(
            [
                'model_name' => $modelName,
                'apps_id' => $app->getKey(),
            ],
            [
                'slug' => Str::simpleSlug($modelName),
            ]
        );
    }

    /**
     * Get by name.
     */
    public static function getByName(string $name, ?AppInterface $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        //this sucks but we need to find the solution
        if ($name === UserFullTableName::class) {
            $name = Users::class;
        }

        return SystemModules::where('name', $name)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get the entity from the input
     */
    public static function getEntityFromInput(SystemModuleInputInterface $entityInput, Users $user, bool $useCompanyReference = true): Model
    {
        $systemModule = self::getByUuidOrModelName($entityInput->systemModuleUuid);

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
        $field = $hasUuid && Str::isUuid($entityInput->entityId) ? 'uuid' : 'id';

        if ($isUser || $isCompany) {
            $entity = $entityModel::where('uuid', $entityInput->entityId)
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
            if ($user->isAppOwner() || ! $useCompanyReference) {
                $entity = $entityModel::where($field, $entityInput->entityId)
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
            } else {
                $entity = $entityModel::where($field, $entityInput->entityId)
                        ->fromApp()
                        ->fromCompany($user->getCurrentCompany())
                        ->notDeleted()
                        ->firstOrFail();
            }
        }

        return $entity;
    }

    /**
     * Get System Module by its uuid or model_name.
     */
    public static function getByUuidOrModelName(string $uuidOrModelName): SystemModules
    {
        $systemModuleSearchField = Uuid::isValid($uuidOrModelName) ? 'uuid' : 'model_name';

        /**
         * @var SystemModules
         */
        return SystemModules::where($systemModuleSearchField, $uuidOrModelName)
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\StateEnums;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class FilesystemEntitiesRepository
{
    /**
     * Get a filesystem entity.
     *
     * @param int $id
     * @param Model $entity
     * @param bool $isDeleted
     *
     * @return FilesystemEntities
     */
    public static function getByIdAdnEntity(int $id, Model $entity, bool $isDeleted = false): FilesystemEntities
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($entity::class);
        $addCompanySql = null;

        // if (!(bool) $app->get('public_images')) {
        //     $companyId = Di::getDefault()->get('userData')->currentCompanyId();
        //     $addCompanySql = 'AND companies_id = :companies_id:';
        //     $bind['companies_id'] = $companyId;
        // }

        return FilesystemEntities::where('id', $id)
                                ->where('system_modules_id', $systemModule->getKey())
                                ->where('is_deleted', StateEnums::NO->getValue())
                                ->whereRaw(
                                    "filesystem_id in (SELECT s.id from filesystem s WHERE s.apps_id = {$app->getKey()})"
                                )
                                ->firstOrFail();
    }

    /**
     * Get files for the given entity.
     *
     * @param Model $entity
     *
     * @return Collection<FilesystemEntities>
     */
    public static function getFilesByEntity(Model $entity): Collection
    {
        $systemModule = SystemModulesRepository::getByModelName($entity::class);

        return FilesystemEntities::join('filesystem', 'filesystem.id', '=', 'filesystem_entities.filesystem_id')
                    ->where('filesystem_entities.entity_id', '=', $entity->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue())
                    ->get();
    }

    /**
     * Given the entity delete all related files.
     *
     * @param Model $entity
     *
     * @return int
     */
    public static function deleteAllFilesFromEntity(Model $entity): int
    {
        $systemModule = SystemModulesRepository::getByModelName($entity::class);

        return FilesystemEntities::where('entity_id', '=', $entity->getKey())
            ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
            ->delete();
    }
}

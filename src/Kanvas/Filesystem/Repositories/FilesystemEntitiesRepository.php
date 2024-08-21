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
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByIdAdnEntity(int $id, Model $entity, bool $isDeleted = false): FilesystemEntities
    {
        $app = $entity->app ?? app(Apps::class);
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
     * @psalm-suppress MixedReturnStatement
     *
     * @return Collection<int, FilesystemEntities>
     */
    public static function getFilesByEntity(Model $entity): Collection
    {
        $app = $entity->app ?? app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($entity::class, $app);

        return FilesystemEntities::join('filesystem', 'filesystem.id', '=', 'filesystem_entities.filesystem_id')
                    ->where('filesystem_entities.entity_id', '=', $entity->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue())
                    ->select(
                        'filesystem_entities.*',
                        'filesystem.url',
                        'filesystem.path',
                        'filesystem.name',
                        'filesystem.apps_id',
                        'filesystem.users_id',
                        'filesystem.size',
                        'filesystem.file_type'
                    )
                    ->get();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public static function getFileFromEntityByName(Model $entity, string $name): ?FilesystemEntities
    {
        $app = $entity->app ?? app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($entity::class, $app);

        return FilesystemEntities::join('filesystem', 'filesystem.id', '=', 'filesystem_entities.filesystem_id')
                    ->where('filesystem_entities.entity_id', '=', $entity->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem_entities.field_name', '=', $name)
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue())
                    ->select(
                        'filesystem_entities.*',
                        'filesystem.url',
                        'filesystem.path',
                        'filesystem.name',
                        'filesystem.apps_id',
                        'filesystem.users_id',
                        'filesystem.size',
                        'filesystem.file_type'
                    )
                    ->first();
    }

    public static function getFileFromEntityById(int $id): ?FilesystemEntities
    {
        return FilesystemEntities::join('filesystem', 'filesystem.id', '=', 'filesystem_entities.filesystem_id')
                    ->where('filesystem_entities.id', '=', $id)
                    ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
                    ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue())
                    ->select(
                        'filesystem_entities.*',
                        'filesystem.url',
                        'filesystem.path',
                        'filesystem.name',
                        'filesystem.apps_id',
                        'filesystem.users_id',
                        'filesystem.size',
                        'filesystem.file_type'
                    )
                    ->first();
    }

    /**
     * Given the entity delete all related files.
     * @psalm-suppress MixedReturnStatement
     */
    public static function deleteAllFilesFromEntity(Model $entity): int
    {
        $app = $entity->app ?? app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($entity::class, $app);

        return FilesystemEntities::where('entity_id', '=', $entity->getKey())
            ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
            ->delete();
    }
}

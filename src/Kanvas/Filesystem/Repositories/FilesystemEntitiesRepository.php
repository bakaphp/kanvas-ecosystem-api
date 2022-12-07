<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\FilesystemEntities\Repositories;

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
    public static function getByIdAdnEntity(int $id, Model $entity, bool $isDeleted = false) : FilesystemEntities
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
                                ->whereRaw("filesystem_id in (SELECT s.id from Filesystem s WHERE s.apps_id = {$app->getKey()}")
                                ->firstOrFail();
    }
}

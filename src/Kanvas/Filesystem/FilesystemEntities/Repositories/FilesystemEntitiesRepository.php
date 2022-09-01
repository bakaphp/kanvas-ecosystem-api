<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\FilesystemEntities\Repositories;

use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;
use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Apps\Apps\Models\Apps;

class FilesystemEntitiesRepository
{
        /**
     * Get a filesystem entities from this system modules.
     *
     * @param int $id
     * @param SystemModules $systemModules
     * @param bool $isDeleted deprecated
     *
     * @return FileSystemEntities
     */
    public static function getByIdWithSystemModule(int $id, SystemModules $systemModules, bool $isDeleted = false)
    {
        $app = app(Apps::class);
        $addCompanySql = null;

        $bind = [
            'id' => $id,
            'system_modules_id' => $systemModules->getKey(),
            'apps_id' => $app->getKey(),
        ];

        // if (!(bool) $app->get('public_images')) {
        //     $companyId = Di::getDefault()->get('userData')->currentCompanyId();
        //     $addCompanySql = 'AND companies_id = :companies_id:';
        //     $bind['companies_id'] = $companyId;
        // }

        return FileSystemEntities::where('id',)
                                ->where('system_modules_id')
                                ->whereRaw("filesystem_id in (SELECT s.id from Filesystem s WHERE s.apps_id = {$app->getKey()}")
                                ->find();


        // return FileSystemEntities::findFirst([
        //     'conditions' => 'id = :id: AND system_modules_id = :system_modules_id: ' . $addCompanySql . '  AND 
        //                         filesystem_id in (SELECT s.id from \Canvas\Models\FileSystem s WHERE s.apps_id = :apps_id: )',
        //     'bind' => $bind
        // ]);
    }
}

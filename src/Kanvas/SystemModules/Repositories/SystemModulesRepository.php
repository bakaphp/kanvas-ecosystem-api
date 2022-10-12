<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;

class SystemModulesRepository
{
    /**
     * Get System Module by its model_name.
     *
     * @param string $model_name
     *
     * @return SystemModules
     */
    public static function getByModelName(string $modelName, ?Apps $app = null) : SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;
        return SystemModules::where('model_name', $modelName)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get by name.
     *
     * @param string $name
     *
     * @return SystemModules
     */
    public static function getByName(string $name, ?Apps $app = null) : SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;
        return SystemModules::where('name', $name)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get System Module by id.
     *
     * @param int $id
     *
     * @return ModelInterface
     */
    public static function getById(int $id, ?Apps $app = null) : SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;
        return SystemModules::where('id', $id)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get System Module by slug
     *
     * @param int $id
     *
     * @return ModelInterface
     */
    public static function getBySlug(string $slug, ?Apps $app = null) : SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;
        return SystemModules::where('string', $slug)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }
}

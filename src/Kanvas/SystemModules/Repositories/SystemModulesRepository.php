<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Repositories;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;

class SystemModulesRepository
{
    /**
     * Get System Module by its model name.
     *
     * @return SystemModules
     */
    public static function getByModelName(string $modelName, ?Apps $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        return SystemModules::firstOrCreate(
            [
                'model_name' => $modelName,
                'apps_id' => $app->getKey()
            ],
            [
                'slug' => Str::simpleSlug($modelName),
            ]
        );
    }

    /**
     * Get by name.
     *
     * @param string $name
     *
     * @return SystemModules
     */
    public static function getByName(string $name, ?Apps $app = null): SystemModules
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
     * @return SystemModules
     */
    public static function getById(int $id, ?Apps $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;
        return SystemModules::where('id', $id)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get System Module by slug.
     *
     * @param int $id
     *
     * @return SystemModules
     */
    public static function getBySlug(string $slug, ?Apps $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;
        return SystemModules::where('string', $slug)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }
}

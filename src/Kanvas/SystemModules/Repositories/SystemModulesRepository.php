<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Repositories;

use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;

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
     *
     * @return SystemModules
     */
    public static function getByModelName(string $modelName, ?Apps $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        return SystemModules::firstOrCreate(
            [
                'model_name' => $modelName,
                'apps_id' => $app->getKey(),
            ],
            [
                'model_name' => $modelName,
                'apps_id' => $app->getKey(),
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
}

<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Repositories;

use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Apps\Apps\Models\Apps;
use Exception;

class SystemModulesRepository
{
    /**
     * Get System Module by its model_name.
     *
     * @param string $model_name
     *
     * @return SystemModules
     */
    public static function getByModelName(string $modelName) : SystemModules
    {
        $app = app(Apps::class);
        $systemModule = SystemModules::where('model_name',$modelName)
                                    ->where('apps_id', $app->getKey())
                                    ->first();

        if (!is_object($systemModule)) {
            throw new Exception('No system module found for ' . $modelName);
        }

        return $systemModule;
    }
}

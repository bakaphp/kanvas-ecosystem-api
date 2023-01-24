<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Actions;

use App\Exceptions\InternalServerErrorException;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;

class CreateInCurrentAppAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Apps $app
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Apps
     */
    public function execute(string $class) : SystemModules
    {
        if (!class_exists($class)) {
            throw new InternalServerErrorException('Class not found in this app');
        }

        return SystemModules::firstOrCreate([
            'model_name' => $class,
            'apps_id' => $this->app->getKey()
        ], [
            'model_name' => $class,
            'name' => $class,
            'apps_id' => $this->app->getKey(),
            'slug' => Str::simpleSlug($class)
        ]);
    }
}

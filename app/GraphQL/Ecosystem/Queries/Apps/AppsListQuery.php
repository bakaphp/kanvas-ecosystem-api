<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;

class AppsListQuery
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     * @param array $request
     * @deprecated
     * 
     * @return array
     */
    public function getAppSettings($rootValue, array $request): array
    {
        $app = AppsRepository::findFirstByKey((string) $request['key']);

        return [
            'name' => $app->name,
            'description' => $app->description,
            'settings' => $app->getAllSettings(true, true),
        ];
    }

    public function getPublicAppSettings($rootValue, array $request): array
    {
        $app = app(Apps::class);

        return [
            'name' => $app->name,
            'description' => $app->description,
            'settings' => $app->getAllSettings(),
        ];
    }
}

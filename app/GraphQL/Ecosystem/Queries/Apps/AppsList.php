<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use Kanvas\Apps\Repositories\AppsRepository;

class AppsList
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     * @param array $request
     *
     * @return array
     */
    public function getAppSettings($rootValue, array $request): array
    {
        $app = AppsRepository::findFirstByKey((string) $request['key']);

        return [
            'name' => $app->name,
            'description' => $app->description,
            'settings' => $app->getAllSettings(onlyPublicSettings: true),
        ];
    }
}

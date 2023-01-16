<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Users\Models\Users;

final class AppsList
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     * @param array $request
     *
     * @return Users
     */
    public function getAppSettings($rootValue, array $request) : array
    {
        $app = AppsRepository::findFirstByKey($request['key']);

        return [
            'name' => $app->name,
            'description' => $app->description,
            'settings' => [
                'data' => $app->getAllSettings(),
            ]
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class ConfigManagement
{
    public function getAppSetting(mixed $root, array $request): array
    {
        return $this->parseSettings(app(Apps::class)->getAll());
    }

    public function getCompanySetting(mixed $root, array $request): array
    {
        return $this->parseSettings(CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class))->getAll());
    }

    public function getUserSetting(mixed $root, array $request): array
    {
        $user = Users::getByUuid($request['entity_uuid']);
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $this->parseSettings($user->getAll());
    }

    public function parseSettings(array $data): array
    {
        $settings = [];
        foreach ($data as $key => $value) {
            $settings[] = [
                'key' => $key,
                'value' => gettype($value) != 'array' ? (string)$value : $value,
            ];
        }

        return $settings;
    }
}

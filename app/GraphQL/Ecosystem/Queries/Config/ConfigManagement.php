<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Companies\Repositories\CompaniesRepository;
class ConfigManagement
{
    public function getAppSetting(mixed $root, array $request): array
    {
        return Apps::getByUuid($request['entity_uuid'], app(Apps::class))->getAll();
    }

    public function getCompanySetting(mixed $root, array $request): array
    {

        return CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class))->getAll();
    }

    public function getUserSetting(mixed $root, array $request): array
    {

        $user = Users::getByUuid($request['entity_uuid']);

        return UsersRepository::belongsToThisApp($user, app(Apps::class))->getAll();
    }
}

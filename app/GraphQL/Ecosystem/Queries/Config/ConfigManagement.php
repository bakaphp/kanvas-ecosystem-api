<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class ConfigManagement
{
    public function getAppSetting(mixed $root, array $request): array
    {
        return Apps::getByUuid($request['entity_uuid'], app(Apps::class))->getAll();
    }

    public function getCompanySetting(mixed $root, array $request): array
    {
        return Companies::getByUuid($request['entity_uuid'], app(Apps::class))->getAll();
    }

    public function getUserSetting(mixed $root, array $request): array
    {
        return Users::getByUuid($request['entity_uuid'], app(Apps::class))->getAll();
    }
}

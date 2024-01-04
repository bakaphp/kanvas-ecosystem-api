<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Config;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class ConfigManagement
{
    public function setAppSetting(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $app->set($request['input']['key'], $request['input']['value']);

        return true;

    }

    public function deleteAppSetting(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $app->set($request['input']['key'], $request['input']['value']);
        $app->delete($request['input']['key']);

        return true;
    }

    public function setCompanySetting(mixed $root, array $request): bool
    {
        $companies = Companies::getByUuid($request['input']['entity_uuid'], app(Apps::class));
        $companies->set($request['input']['key'], $request['input']['value']);

        return true;
    }

    public function deleteCompanySetting(mixed $root, array $request): bool
    {
        $companies = Companies::getByUuid($request['input']['entity_uuid'], app(Apps::class));
        $companies->delete($request['input']['key']);

        return true;
    }

    public function setUserSetting(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['input']['entity_uuid'], app(Apps::class));
        $user->set($request['input']['key'], $request['input']['value']);

        return true;
    }

    public function deleteUserSetting(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['input']['entity_uuid'], app(Apps::class));

        $user->delete($request['input']['key']);

        return true;
    }
}

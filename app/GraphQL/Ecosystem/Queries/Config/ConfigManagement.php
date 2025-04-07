<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class ConfigManagement
{
    public function getAppSetting(mixed $root, array $request): array
    {
        $user = auth()->user();

        return $this->parseSettings(app(Apps::class)->getAll(false, true), $user);
    }

    public function getAppSettingByKey(mixed $root, array $request): mixed
    {
        //$user = auth()->user();

        return app(Apps::class)->get($request['key']);
    }

    public function getCompanySetting(mixed $root, array $request): array
    {
        $user = auth()->user();

        return $this->parseSettings(CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class))->getAll(false, true), $user);
    }

    public function getCompanySettingByKey(mixed $root, array $request): mixed
    {
        return CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class))->get($request['key']);
    }

    public function getUserSetting(mixed $root, array $request): array
    {
        $user = Users::getByUuid($request['entity_uuid']);
        $currentUser = auth()->user();
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $this->parseSettings($user->getAll(false, true), $currentUser);
    }

    public function parseSettings(array $data, UserInterface $user): array
    {
        $settings = [];
        foreach ($data as $key => $value) {
            $settings[] = [
                'key' => $key,
                'value' => gettype($value['value']) != 'array' ? (string)$value['value'] : $value['value'],
                'public' => $user->isAdmin() ? (bool) $value['public'] : false,
            ];
        }

        return $settings;
    }
}

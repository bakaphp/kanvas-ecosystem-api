<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

use Baka\Contracts\HashTableInterface;
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

        return $this->parseSettings(app(Apps::class), $user);
    }

    public function getAppSettingByKey(mixed $root, array $request): mixed
    {
        $app = app(Apps::class);

        return $app->isSecret($request['key']) ? null : $app->get($request['key']);
    }

    public function getCompanySetting(mixed $root, array $request): array
    {
        $user = auth()->user();

        return $this->parseSettings(CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class)), $user);
    }

    public function getCompanySettingByKey(mixed $root, array $request): mixed
    {
        $company = CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class));

        return $company->isSecret($request['key']) ? null : $company->get($request['key']);
    }

    public function getUserSetting(mixed $root, array $request): array
    {
        $user = Users::getByUuid($request['entity_uuid']);
        $currentUser = auth()->user();
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $this->parseSettings($user, $currentUser);
    }

    public function parseSettings(HashTableInterface $entity, UserInterface $user): array
    {
        $settings = [];
        foreach ($entity->getAll(false, true) as $key => $value) {
            $settings[] = [
                'key' => $key,
                'value' => $entity::isEncryptedValue($value['value']) ? null : (gettype($value['value']) != 'array' ? (string) $value['value'] : $value['value']),
                'public' => $user->isAdmin() ? (bool) $value['public'] : false,
            ];
        }

        return $settings;
    }
}

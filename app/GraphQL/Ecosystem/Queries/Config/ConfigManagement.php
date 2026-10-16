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
        return $this->parseSettings(app(Apps::class), auth()->user());
    }

    public function getAppSettingByKey(mixed $root, array $request): mixed
    {
        return $this->unlessSecret(app(Apps::class), $request['key']);
    }

    public function getCompanySetting(mixed $root, array $request): array
    {
        return $this->parseSettings(CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class)), auth()->user());
    }

    public function getCompanySettingByKey(mixed $root, array $request): mixed
    {
        $company = CompaniesRepository::getByUuid($request['entity_uuid'], app(Apps::class));

        return $this->unlessSecret($company, $request['key']);
    }

    public function getUserSetting(mixed $root, array $request): array
    {
        $user = Users::getByUuid($request['entity_uuid']);
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $this->parseSettings($user, auth()->user());
    }

    private function unlessSecret(HashTableInterface $entity, string $key): mixed
    {
        return $entity->isSecret($key) ? null : $entity->get($key);
    }

    private function parseSettings(HashTableInterface $entity, UserInterface $user): array
    {
        $isAdmin = $user->isAdmin();
        $settings = [];
        foreach ($entity->getAll(publicFormat: true) as $key => $value) {
            $settings[] = [
                'key' => $key,
                'value' => match (true) {
                    $entity::isEncryptedValue($value['value']) => null,
                    is_array($value['value']) => $value['value'],
                    default => (string) $value['value'],
                },
                'public' => $isAdmin && $value['public'],
            ];
        }

        return $settings;
    }
}

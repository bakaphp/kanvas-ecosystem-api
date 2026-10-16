<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Config;

use Baka\Contracts\HashTableInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class ConfigManagement
{
    public function setAppSetting(mixed $root, array $request): bool
    {
        $this->store(app(Apps::class), $request['input']);

        return true;
    }

    public function deleteAppSetting(mixed $root, array $request): bool
    {
        $app = app(Apps::class);

        return $app->del($request['key']);
    }

    public function setCompanySetting(mixed $root, array $request): bool
    {
        $companies = CompaniesRepository::getByUuid($request['input']['entity_uuid'], app(Apps::class));
        $this->store($companies, $request['input']);

        return true;
    }

    public function deleteCompanySetting(mixed $root, array $request): bool
    {
        $companies = CompaniesRepository::getByUuid($request['input']['entity_uuid'], app(Apps::class));
        $companies->del($request['input']['key']);

        return true;
    }

    public function setUserSetting(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['input']['entity_uuid']);

        UsersRepository::belongsToThisApp($user, app(Apps::class));
        $this->store($user, $request['input']);

        return true;
    }

    public function deleteUserSetting(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['input']['entity_uuid']);

        UsersRepository::belongsToThisApp($user, app(Apps::class));
        $user->set($request['input']['key'], $request['input']['value']);
        $user->del($request['input']['key']);

        return true;
    }

    private function store(HashTableInterface $entity, array $input): void
    {
        if (! empty($input['secret'])) {
            $entity->setEncrypted($input['key'], $input['value']);

            return;
        }

        $isPublic = auth()->user()->isAdmin() && isset($input['public']) ? (bool) $input['public'] : false;
        $entity->set($input['key'], $input['value'], $isPublic);
    }
}

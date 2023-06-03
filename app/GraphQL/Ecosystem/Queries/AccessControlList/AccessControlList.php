<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\AccessControlList;

use Kanvas\Users\Repositories\UsersRepository;

class AccessControlList
{
    public function getAllAbilities(mixed $root, array $query): array
    {
        $abilities = UsersRepository::getUserOfCompanyById(auth()->user()->defaultCompany, $query['userId'])->getAbilities();

        $mapAbilities = $abilities->map(function ($ability) {
            return $ability->name;
        });

        return $mapAbilities->all();
    }
}

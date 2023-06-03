<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\AccessControlList;

use Kanvas\Users\Repositories\UsersRepository;

class AccessControlList
{
    /**
     * getAllAbilities

     * */
    public function getAllAbilities(mixed $root, array $query): array
    {
        $abilities = UsersRepository::getUserOfCompanyById(auth()->user()->defaultCompany->id, $query['userId'])->getAbilities();
        $mapAbilities = $abilities->map(function ($ability) {
            return $ability->name;
        });

        return $mapAbilities->all();
    }
}

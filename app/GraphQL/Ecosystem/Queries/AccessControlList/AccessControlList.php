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
        $abilities = UsersRepository::getById($query['userId'], auth()->user()->defaultCompany->id)->getAbilities();
        $mapAbilities = $abilities->map(function ($ability) {
            return $ability->name;
        });

        return $mapAbilities->all();
    }
}

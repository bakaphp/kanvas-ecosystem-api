<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Resolvers\AccessControlList;

use Kanvas\AccessControlList\Repositories\RolesRepository;
use Illuminate\Database\Eloquent\Collection;

class RolesResolver
{
    /**
     * getAllRoles
     *
     * @return Collection
     */
    public function getAllRoles(): ?Collection
    {
        return RolesRepository::getAllRoles();
    }
}

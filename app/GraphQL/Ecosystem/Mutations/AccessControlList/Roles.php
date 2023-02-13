<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\UpdateRoleAction;
use Kanvas\Companies\Models\Companies;
use Silber\Bouncer\Database\Role as SilberRole;

class Roles
{
    /**
     * createRole.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return void
     */
    public function createRole(mixed $rootValue, array $request): SilberRole
    {
        $role = new CreateRoleAction(
            $request['name'],
            $request['title']
        );
        return $role->execute(Companies::getById(auth()->user()->currentCompanyId()));
    }

    /**
     * updateRole.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return void
     */
    public function updateRole(mixed $rootValue, array $request): SilberRole
    {
        $role = new UpdateRoleAction(
            $request['id'],
            $request['name'],
            $request['title'] ?? null
        );
        return $role->execute(Companies::getById(auth()->user()->currentCompanyId()));
    }
}

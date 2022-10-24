<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Kanvas\AccessControlList\Actions\CreateRole;
use Silber\Bouncer\Database\Role as SilberRole;
use Kanvas\AccessControlList\Actions\UpdateRole;

class Roles
{
    /**
     * createRole
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return void
     */
    public function createRole($rootValue, array $request): SilberRole
    {
        $role = new CreateRole(
            $request['name'],
            $request['title']
        );
        return $role->execute();
    }
    /**
     * updateRole
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return void
     */
    public function updateRole($rootValue, array $request): SilberRole
    {
        $role = new UpdateRole(
            $request['id'],
            $request['name'],
            $request['title'] ?? null
        );
        return $role->execute();
    }
}

<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Resolvers\ACL;

use Kanvas\ACL\Actions\CreateRole;
use Silber\Bouncer\Database\Role as SilberRole;

class RolesResolver
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
}

<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Queries;

use Bouncer;
use Kanvas\AccessControlList\Repositories\RolesRepository;

class Roles
{
    public function __invoke()
    {
        return RolesRepository::getAllRoles();
    }
}

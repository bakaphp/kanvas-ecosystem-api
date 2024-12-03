<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Repositories\UserRoleRepository;
use Tests\TestCase;

final class UserRoleTest extends TestCase
{
    public function testGetAllUsersOfRole()
    {
        $app = app(Apps::class);

        $role = RolesRepository::getByNameFromCompany(
            name: RolesEnums::ADMIN->value,
            app: $app
        );

        $allUsersOfRole = UserRoleRepository::getAllUsersOfRole(
            role: $role,
            app: $app
        );

        $this->assertGreaterThanOrEqual(1, $allUsersOfRole->count());
    }
}

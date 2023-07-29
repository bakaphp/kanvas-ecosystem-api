<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Roles\Models\Roles;
use Tests\TestCase;

class UserInviteTest extends TestCase
{
    public function testInviteNewUser(): void
    {
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteUser($data: InviteInput!) {
                inviteUser(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'role_id' => RolesRepository::getByNameFromCompany('Users')->id,
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                ],
            ]
        );

        print_R($response->json());
        die();
    }
}

<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\AccessControlList\Repositories\RolesRepository;
use Tests\TestCase;

class AccessControlListTest extends TestCase
{
    /**
     * testCreateRole.
     */
    public function testCreateRole(): void
    {
        $user = auth()->user();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation(
                $name: String!
                $title: String
            ) {
                createRole(
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'name' => 'No Admin',
                'title' => 'No Admin',
            ]
        )->assertJson([
            'data' => [
                'createRole' => [
                    'name' => 'No Admin',
                    'title' => 'No Admin',
                ],
            ],
        ]);
    }

    /**
     * testGetRole.
     */
    public function testGetRole(): void
    {
        $user = auth()->user();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation(
                $name: String!
                $title: String
            ) {
                createRole(
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'name' => 'No Admin',
                'title' => 'No Admin',
            ]
        );

        $response = $this->graphQL(/** @lang GraphQL */
            '
            {
                roles{
                    name,
                    id
                }
            }
            '
        );
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * testUpdateRole.
     */
    public function testUpdateRole(): void
    {
        $user = auth()->user();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation(
                $name: String!
                $title: String
            ) {
                createRole(
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'name' => 'No Admin',
                'title' => 'No Admin',
            ]
        );

        $response = $this->graphQL(/** @lang GraphQL */
            '
            {
                roles{
                    name,
                    id
                }
            }
            '
        );
        $id = $response->json('data.roles.*.id');

        $faker = \Faker\Factory::create();
        $newName = $faker->name;

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $id: Int!
                $name: String!
                $title: String
            ) {
                updateRole(
                    id: $id
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'id' => $id[0],
                'name' => $newName,
                'title' => 'Role Updated',
            ]
        )->assertJson([
            'data' => [
                'updateRole' => [
                    'name' => $newName,
                    'title' => 'Role Updated',
                    'id' => $id[0],
                ],
            ],
        ]);
    }

    public function testAssignUserRole()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $roles = RolesRepository::getAllRoles()->first();


        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: Int!
                $role: Mixed!
            ) {
                assignRoleToUser(
                    userId: $userId
                    role: $role
                ) 
            }',
            [
                'userId' => $user->getId(),
                'role' => $roles->id,
            ]
        )->assertJson([
            'data' => [
                'assignRoleToUser' => true,
            ],
        ]);
    }
}

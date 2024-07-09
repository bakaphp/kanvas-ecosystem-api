<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use Kanvas\Apps\Enums\DefaultRoles;
use Tests\TestCase;

class RolesTest extends TestCase
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
                    userCount
                    abilitiesCount
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
                    userCount
                    abilitiesCount
                    systemRole
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
                    data{
                        name,
                        id
                    }
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
        $faker = \Faker\Factory::create();
        $newName = $faker->name;

        $create = $this->graphQL( /** @lang GraphQL */
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
                'name' => $newName,
                'title' => 'No Admin',
            ]
        );


        $id = $create->json('data.createRole.id');
        $faker = \Faker\Factory::create();
        $newName = $faker->name;

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $id: ID!
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
                    userCount
                    abilitiesCount
                }
            }',
            [
                'id' => $id,
                'name' => $newName,
                'title' => 'Role Updated',
            ]
        )->assertJson([
            'data' => [
                'updateRole' => [
                    'name' => $newName,
                    'title' => 'Role Updated',
                    'id' => $id,
                ],
            ],
        ]);
    }

    public function testAssignUserRole()
    {
        $user = auth()->user();
        $faker = \Faker\Factory::create();
        $newName = $faker->name;

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
                'name' => $newName,
                'title' => 'No Admin',
            ]
        );


        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $role: Mixed!
            ) {
                assignRoleToUser(
                    userId: $userId
                    role: $role
                ) 
            }',
            [
                'userId' => $user->getId(),
                'role' => $newName,
            ]
        )->assertJson([
            'data' => [
                'assignRoleToUser' => true,
            ],
        ]);
    }

    public function testHasRole()
    {
        $user = auth()->user();
        $faker = \Faker\Factory::create();
        $newName = $faker->name;

        $this->graphQL(/** @lang GraphQL */
            '
            query(
                $userId: ID!
                $role: Mixed!
            ) {
                hasRole(
                    userId: $userId
                    role: $role
                ) 
            }',
            [
                'userId' => $user->getId(),
                'role' => DefaultRoles::ADMIN->getValue(),
            ]
        )->assertJson([
            'data' => [
                'hasRole' => true,
            ],
        ]);
    }

    public function testRemoveAssignUserRole()
    {
        $user = auth()->user();
        $faker = \Faker\Factory::create();
        $newName = $faker->name;

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
                'name' => $newName,
                'title' => 'No Admin',
            ]
        );


        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $role: Mixed!
            ) {
                assignRoleToUser(
                    userId: $userId
                    role: $role
                ) 
            }',
            [
                'userId' => $user->getId(),
                'role' => $newName,
            ]
        )->assertJson([
            'data' => [
                'assignRoleToUser' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $role: Mixed!
            ) {
                removeRole(
                    userId: $userId
                    role: $role
                ) 
            }',
            [
                'userId' => $user->getId(),
                'role' => $newName,
            ]
        )->assertJson([
            'data' => [
                'removeRole' => true,
            ],
        ]);
    }
}

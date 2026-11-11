<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use ErrorException;
use Tests\TestCase;

class UsersAbilitiesTest extends TestCase
{
    public function testGetAllAbilities()
    {
        $user = auth()->user();

        $this->graphQL(/** @lang GraphQL */
            '
            query getAllAbilities(
                $userId: ID!
            ) {
                getAllAbilities(
                    userId: $userId
                ) 
            }',
            [
                'userId' => $user->getId(),
            ]
        )->assertJson([
            'data' => [
                'getAllAbilities' => [],
            ],
        ]);
    }

    public function testGetAllAbilitiesWithoutCompanyIdRaisesNoWarning()
    {
        // The app only logs warnings, so the request succeeds either way; escalate them to see one.
        set_error_handler(
            static fn (int $level, string $message, string $file, int $line) => throw new ErrorException($message, 0, $level, $file, $line),
            E_WARNING
        );

        try {
            $response = $this->graphQL(/** @lang GraphQL */
                '
                query getAllAbilities($userId: ID!) {
                    getAllAbilities(userId: $userId)
                }',
                [
                    'userId' => auth()->user()->getId(),
                ]
            );
        } finally {
            restore_error_handler();
        }

        $response->assertJsonMissingPath('errors');
        $response->assertJson([
            'data' => [
                'getAllAbilities' => [],
            ],
        ]);
    }

    public function testGiveUserPermission()
    {
        $user = auth()->user();

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $permission: String!
            ) {
                givePermissionToUser(
                    userId: $userId
                    permission: $permission
                )
            }',
            [
                'userId' => $user->getId(),
                'permission' => 'invite-users',
            ]
        )->assertJson([
            'data' => [
                'givePermissionToUser' => true,
            ],
        ]);
    }

    public function testRemoveUserPermission()
    {
        $user = auth()->user();

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $permission: String!
            ) {
                givePermissionToUser(
                    userId: $userId
                    permission: $permission
                )
            }',
            [
                'userId' => $user->getId(),
                'permission' => 'invite-users',
            ]
        )->assertJson([
            'data' => [
                'givePermissionToUser' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $permission: String!
            ) {
                removePermissionToUser(
                    userId: $userId
                    permission: $permission
                )
            }',
            [
                'userId' => $user->getId(),
                'permission' => 'invite-users',
            ]
        )->assertJson([
            'data' => [
                'removePermissionToUser' => true,
            ],
        ]);
    }

    public function testDoesUserHaveThisPermission()
    {
        $user = auth()->user();

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $userId: ID!
                $permission: String!
            ) {
                givePermissionToUser(
                    userId: $userId
                    permission: $permission
                )
            }',
            [
                'userId' => $user->getId(),
                'permission' => 'invite-users',
            ]
        )->assertJson([
            'data' => [
                'givePermissionToUser' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */
            '
            query(
                $userId: ID!
                $permission: String!
            ) {
                can(
                    userId: $userId
                    permission: $permission
                )
            }',
            [
                'userId' => $user->getId(),
                'permission' => 'invite-users',
            ]
        )->assertJson([
            'data' => [
                'can' => true,
            ],
        ]);
    }
}

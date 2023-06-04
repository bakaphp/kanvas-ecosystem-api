<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use Tests\TestCase;

class UsersAbilitiesTest extends TestCase
{
    public function testGetAllAbilities()
    {
        $user = auth()->user();

        $this->graphQL(/** @lang GraphQL */
            '
            query getAllAbilities(
                $userId: Int!
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
}

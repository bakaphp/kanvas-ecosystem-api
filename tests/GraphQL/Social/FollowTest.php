<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Users\Models\Users;
use Tests\TestCase;

class FollowTest extends TestCase
{
    /**
     * testFollowUser
     */
    public function testFollowUser(): void
    {
        $user = Users::factory()->create();
        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: Int!
            ) {
                userFollow(user_id: $user_id)
            }
            ',
            [
                'user_id' => $user->id,
            ]
        );
        $response->assertJson([
            'data' => ['userFollow' => true],
        ]);
    }

    /**
     * testUnFollowUser
     */
    public function testUnFollowUser(): void
    {
        $user = Users::factory()->create();
        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: Int!
            ) {
                userFollow(user_id: $user_id)
            }
            ',
            [
                'user_id' => $user->id,
            ]
        );
        $response->assertJson([
            'data' => ['userFollow' => true],
        ]);
        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation userUnFollow(
                $user_id: Int!
            ) {
                userUnFollow(user_id: $user_id)
            }
            ',
            [
                'user_id' => $user->id,
            ]
        )->assertJson(
            [
            'data' => ['userUnFollow' => true],
        ]
        );
    }

    /**
     * testFollowUser
     */
    public function testIsFollowing(): void
    {
        $user = Users::factory()->create();
        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: Int!
            ) {
                userFollow(user_id: $user_id)
            }
            ',
            [
                'user_id' => $user->id,
            ]
        );
        $response->assertJson([
            'data' => ['userFollow' => true],
        ]);
        $response = $this->graphQL(/** @lang GraphQL */
            'query isFollowing($user_id: Int!)
            {
                isFollowing(
                    user_id: $user_id
                )
            }
            ',
            [
                'user_id' => $user->id,
            ]
        );
        $response->assertJson([
            'data' => ['isFollowing' => true],
        ]);
    }
}

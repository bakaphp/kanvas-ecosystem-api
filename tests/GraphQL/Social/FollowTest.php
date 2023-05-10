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

    /**
     * testGetFollowers
     */
    public function testGetFollowers(): void
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

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query getFollowers($users_id: Int!)
            {
                getFollowers(
                    users_id: $users_id
                )
                {
                    data {
                        email
                    }
                }
            }
            ',
            [
                'users_id' => $user->id,
            ]
        )->assertJson(
            [
            'data' => [
                'getFollowers' => [
                    'data' => [
                        [
                            'email' => auth()->user()->email,
                        ],
                    ],
                ],
            ],
        ]
        );
    }

    public function testGetTotalFollowers(): void
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

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query getTotalFollowers($users_id: Int!)
            {
                getTotalFollowers(
                    users_id: $users_id
                )
            }
            ',
            [
                'users_id' => $user->id,
            ]
        )->assertJson(
            [
            'data' => [
                'getTotalFollowers' => 1,
            ],
        ]
        );
    }

    /**
     * testGetFollowing
     */
    public function testGetFollowing(): void
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
            query getFollowing($users_id: Int!)
            {
                getFollowing(
                    users_id: $users_id
                )
                {
                    data {
                        entity {
                            email
                        }
                    }
                }
            }
            ',
            [
                'users_id' => auth()->user()->id,
            ]
        )->assertJson(
            [
            'data' => [
                'getFollowing' => [
                    'data' => [
                        [
                            'entity' => [
                                'email' => $user->email,
                            ],
                        ],
                    ],
                ],
            ],
        ]
        );
    }
}

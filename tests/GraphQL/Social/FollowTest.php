<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Users\Actions\AssignCompanyAction;
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
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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

        $this->graphQL(/** @lang GraphQL */ '
            { 
                me {
                    social {
                        total_following
                    }
                }
            }
        ')->assertJsonFragment([
            'data' => [
                'me' => [
                    'social' => [
                        'total_following' => 2, //test has another one that add a follower
                    ],
                ],
            ],
        ]);
    }

    /**
     * testUnFollowUser
     */
    public function testUnFollowUser(): void
    {
        $user = Users::factory()->create();
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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
                $user_id: ID!
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
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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
            'query isFollowing($user_id: ID!)
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

        $this->graphQL(/** @lang GraphQL */ '
            { 
                user(id: ' . $user->id . ') {
                    social {
                        total_followers
                    }
                }
            }
        ')->assertJsonFragment([
            'data' => [
                'user' => [
                    'social' => [
                        'total_followers' => 1,
                    ],
                ],
            ],
        ]);
    }

    /**
     * testGetFollowers
     */
    public function testGetFollowers(): void
    {
        $user = Users::factory()->create();
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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
            query getFollowers($user_id: ID!)
            {
                getFollowers(
                    user_id: $user_id
                )
                {
                    data {
                        email
                    }
                }
            }
            ',
            [
                'user_id' => $user->id,
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
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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
            query getTotalFollowers($user_id: ID!)
            {
                getTotalFollowers(
                    user_id: $user_id
                )
            }
            ',
            [
                'user_id' => $user->id,
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
    public function testGetUserFollowing(): void
    {
        $user = Users::factory()->create();
        $branch = auth()->user()->getCurrentBranch();

        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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
            query getUserFollowing($user_id: ID!)
            {
                getUserFollowing(
                    user_id: $user_id
                )
                {
                    data {
                        id
                            email
                    }
                }
            }
            ',
            [
                'user_id' => auth()->user()->id,
            ]
        )->assertSee($user->email);
    }

    public function testGetFollowing(): void
    {
        $user = Users::factory()->create();
        $branch = auth()->user()->getCurrentBranch();

        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation userFollow(
                $user_id: ID!
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
            query getFollowing($user_id: ID!)
            {
                getFollowing(
                    user_id: $user_id
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
                'user_id' => auth()->user()->id,
            ]
        )->assertJsonFragment(
            [
                'entity' => [
                    'email' => $user->email,
                ],
            ]
        );
    }
}

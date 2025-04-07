<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class FollowTest extends TestCase
{
    public function testFollowUser(): void
    {
        $user = auth()->user();
        $userToFollow = Users::factory()->create();

        $this->assertInstanceOf(
            UsersFollows::class,
            $user->follow($userToFollow)
        );
    }

    public function testIsFollowingUser(): void
    {
        $user = auth()->user();
        $userToFollow = Users::factory()->create();
        $user->follow($userToFollow);

        $this->assertTrue(
            $user->isFollowing($userToFollow)
        );
    }

    public function testUnFollowUser(): void
    {
        $user = auth()->user();
        $userToFollow = Users::factory()->create();
        $user->follow($userToFollow);

        $this->assertTrue(
            $user->isFollowing($userToFollow)
        );

        $user->unFollow($userToFollow);

        $this->assertFalse(
            $user->isFollowing($userToFollow)
        );
    }

    public function testSocialCounterReset(): void
    {
        $user = auth()->user();
        $userToFollow = Users::factory()->create();
        $user->follow($userToFollow);
        $app = app(Apps::class);

        $this->assertTrue(
            $user->isFollowing($userToFollow)
        );

        $user->resetSocialCount($app);

        $this->assertEquals(
            0,
            $user->getTotalFollowers($app)
        );

        $this->assertEquals(
            1,
            $user->getTotalFollowing($app)
        );
    }
}

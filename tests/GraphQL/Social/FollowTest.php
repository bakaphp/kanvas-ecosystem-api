<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Users\Models\Users;
use Tests\TestCase;

class FollowTest extends TestCase
{
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
}

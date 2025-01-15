<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Tests\TestCase;

class UsersRatingsTest extends TestCase
{
    public function testCreateUsersRatings()
    {
        $input = [
            'system_module_id' => 1,
            'entity_id' => 1,
            'rating' => 5.0,
            'comment' => 'Great',
        ];
        $this->graphQL(
            '
                mutation createUserRating($input: UsersRatingsInput!) {
                    createUserRating(input: $input) {
                        rating
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createUserRating' => [
                    'rating' => 5.0,
                ],
            ],
        ]);
    }
}

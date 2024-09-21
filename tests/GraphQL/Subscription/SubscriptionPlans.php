<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Tests\TestCase;

final class SubscriptionPlans extends TestCase
{
    public function testListPlans(): void
    {
        $response = $this->graphQL(
            'query {
                subscriptionPlans {
                    data
                    {
                        id
                        name
                        description
                        stripe_id
                        prices {
                            id
                            stripe_id
                            amount
                        }
                    }
                }
            }'
        );

        $response->assertJsonStructure([
            'data' => [
                'subscriptionPlans' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'stripe_id',
                            'prices' => [
                                '*' => [
                                    'id',
                                    'stripe_id',
                                    'amount',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}

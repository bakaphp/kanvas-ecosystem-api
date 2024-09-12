<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscriptions;

use Tests\TestCase;

class SubscriptionItemsTest extends TestCase
{
    public function testCreateSubscriptionItem(): void
    {
        $response = $this->graphQL('
            mutation {
                createSubscriptionItem(input: {
                    subscription_id: 1,
                    apps_plans_id: 1,
                    stripe_price_id: "price_1JXXXXXXXXXXXX",
                    quantity: 2
                }) {
                    id
                    subscription_id
                    apps_plans_id
                    stripe_price_id
                    quantity
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createSubscriptionItem' => [
                    'subscription_id' => 1,
                    'apps_plans_id' => 1,
                    'stripe_price_id' => 'price_1JXXXXXXXXXXXX',
                    'quantity' => 2,
                ],
            ],
        ]);
    }

    public function testUpdateSubscriptionItem(): void
    {
        $response = $this->graphQL('
            mutation {
                updateSubscriptionItem(id: 1, input: {
                    stripe_price_id: "price_1JXXXXXXXXXXXX",
                    quantity: 3
                }) {
                    id
                    subscription_id
                    apps_plans_id
                    stripe_price_id
                    quantity
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updateSubscriptionItem' => [
                    'subscription_id' => 1,
                    'apps_plans_id' => 1,
                    'stripe_price_id' => 'price_1JXXXXXXXXXXXX',
                    'quantity' => 3,
                ],
            ],
        ]);
    }

    public function testDeleteSubscriptionItem(): void
    {
        $response = $this->graphQL('
            mutation {
                deleteSubscriptionItem(id: 1)
            }
        ');

        $response->assertJson([
            'data' => [
                'deleteSubscriptionItem' => true,
            ],
        ]);
    }
}

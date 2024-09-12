<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscriptions;

use Tests\TestCase;

class SubscriptionsTest extends TestCase
{
    public function testCreateSubscription(): void
    {
        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    app_plan_id: 1,
                    items: [
                        { stripe_price_id: "price_1JXXXXXXXXXXXX", quantity: 2 }
                    ],
                    name: "Test Subscription",
                    payment_method_id: "pm_card_visa",
                    trial_days: 14
                }) {
                    id
                    name
                    items {
                        id
                        stripe_price_id
                        quantity
                    }
                    payment_method_id
                    is_active
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createSubscription' => [
                    'name' => 'Test Subscription',
                    'payment_method_id' => 'pm_card_visa',
                    'is_active' => true,
                ],
            ],
        ]);
    }

    public function testUpdateSubscription(): void
    {
        $response = $this->graphQL('
            mutation {
                updateSubscription(id: 1, input: {
                    app_plan_id: 1,
                    items: [
                        { stripe_price_id: "price_1JXXXXXXXXXXXX", quantity: 3 }
                    ],
                    name: "Updated Subscription",
                    payment_method_id: "pm_card_mastercard"
                }) {
                    id
                    name
                    items {
                        id
                        stripe_price_id
                        quantity
                    }
                    payment_method_id
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updateSubscription' => [
                    'name' => 'Updated Subscription',
                    'payment_method_id' => 'pm_card_mastercard',
                    'items' => [
                        [
                            'stripe_price_id' => 'price_1JXXXXXXXXXXXX',
                            'quantity' => 3,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testDeleteSubscription(): void
    {
        $response = $this->graphQL('
            mutation {
                deleteSubscription(id: 1)
            }
        ');

        $response->assertJson([
            'data' => [
                'deleteSubscription' => true,
            ],
        ]);
    }
}

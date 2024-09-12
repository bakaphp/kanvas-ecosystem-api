<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscriptions;

use Tests\TestCase;

class PlansTest extends TestCase
{
    public function testCreatePlan(): void
    {
        $response = $this->graphQL('
            mutation {
                createPlan(input: {
                    name: "Basic Plan",
                    description: "This is a basic plan",
                    prices: [
                        { amount: 1000, currency: "usd", interval: "month" }
                    ],
                    is_default: true
                }) {
                    id
                    name
                    description
                    prices {
                        amount
                        currency
                        interval
                    }
                    is_default
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createPlan' => [
                    'name' => 'Basic Plan',
                    'description' => 'This is a basic plan',
                    'is_default' => true,
                ],
            ],
        ]);
    }

    public function testUpdatePlan(): void
    {
        $response = $this->graphQL('
            mutation {
                updatePlan(id: 1, input: {
                    name: "Updated Plan",
                    description: "Updated description"
                }) {
                    id
                    name
                    description
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updatePlan' => [
                    'name' => 'Updated Plan',
                    'description' => 'Updated description',
                ],
            ],
        ]);
    }

    public function testDeletePlan(): void
    {
        $response = $this->graphQL('
            mutation {
                deletePlan(id: 1)
            }
        ');

        $response->assertJson([
            'data' => [
                'deletePlan' => true,
            ],
        ]);
    }
}

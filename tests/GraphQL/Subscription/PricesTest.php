<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscriptions;

use Tests\TestCase;

class PricesTest extends TestCase
{
    public function testCreatePrice(): void
    {
        $response = $this->graphQL('
            mutation {
                createPrice(input: {
                    apps_plans_id: 1,
                    amount: 2000,
                    currency: "usd",
                    interval: "month"
                }) {
                    id
                    apps_plans_id
                    amount
                    currency
                    interval
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createPrice' => [
                    'apps_plans_id' => 1,
                    'amount' => 2000,
                    'currency' => 'usd',
                    'interval' => 'month',
                ],
            ],
        ]);
    }

    public function testUpdatePrice(): void
    {
        $response = $this->graphQL('
            mutation {
                updatePrice(id: 1, input: {
                    amount: 3000,
                    currency: "usd",
                    interval: "year"
                }) {
                    id
                    apps_plans_id
                    amount
                    currency
                    interval
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updatePrice' => [
                    'amount' => 3000,
                    'currency' => 'usd',
                    'interval' => 'year',
                ],
            ],
        ]);
    }

    public function testDeletePrice(): void
    {
        $response = $this->graphQL('
            mutation {
                deletePrice(id: 1)
            }
        ');

        $response->assertJson([
            'data' => [
                'deletePrice' => true,
            ],
        ]);
    }
}

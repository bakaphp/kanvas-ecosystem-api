<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Models\Apps;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use InventoryCases;

    /**
     * Test Get Activity Log
     */
    public function testGetActivityLog(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'weight' => 1,
            'attributes' => [
                [
                    'name' => fake()->name,
                    'value' => fake()->name,
                ],
            ],
        ];

        $response = $this->createProduct($data);
        unset($data['id']);
        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);

        $productId = $response->json()['data']['createProduct']['id'];

        $response = $this->graphQL(/** @lang GraphQL */ '
            query getActivityLog($first: Int, $productId: Mixed!) {
                getActivityLog(
                    where: {column: MODEL_ID, operator: EQ, value: $productId}
                    first: $first
                ) {
                    data {
                        id
                        log_name
                        description
                        model_name
                        model_id
                        event
                        changes
                        created_at
                    }
                }
            }
        ', [
            'first' => 1,
            'productId' => $productId
        ]);

        $this->assertArrayHasKey('data', $response->json());
    }
}

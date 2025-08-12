<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class BundleTest extends TestCase
{
    use InventoryCases;

    public function testCreateBundle(): void
    {
        $user = Auth::user();

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $region = Regions::getDefault($user->getCurrentCompany(), app(Apps::class));
        $this->assertIsArray($productResponse);
        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];

        $warehouseData = [
                    'id' => $warehouseResponse['id'],
                ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];
        $bundleData = [
            'name' => fake()->name,
            'description' => fake()->text,
            'variants' => [
                [
                    'id' => $variantResponse['id'],
                    'quantity' => 1,
                    'unit' => 'unit',
                ],
                ],
            'execution_mode' => 'manual',
            'expose_as_product' => false,
        ];
       $response = $this->graphQL(
            '
            mutation($data: BundleInput!) {
                createBundle(input: $data) {
                    id
                    name
                    description
                    execution_mode
                    expose_as_product
                    bundleItems(first: 10) {
                        data {
                            quantity
                            unit
                        }
                    }
                }
            }',
            ['data' => $bundleData]
        );
        dump($response->json());

        // ->assertJsonStructure([
                    // 'data' => [
                        // 'createBundle' => [
                            // 'id',
                            // 'name',
                            // 'description',
                            // 'execution_mode',
                            // 'expose_as_product',
                            // 'variants' => [
                                // '*' => [
                                    // 'id',
                                    // 'quantity',
                                    // 'unit',
                                // ],
                            // ],
                        // ],
                    // ],
                // ])
                // ->assertJsonPath('data.createBundle.name', $bundleData['name']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class BundleTest extends TestCase
{
    use InventoryCases;

    private array $productResponse;
    private array $warehouseResponse;
    private array $variantResponse;
    private array $warehouseData;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $region = Regions::getDefault($user->getCurrentCompany(), app(Apps::class));

        // Create product
        $this->productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        // Create warehouse
        $this->warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $this->warehouseData = [
            'id' => $this->warehouseResponse['id'],
        ];

        // Create variant
        $this->variantResponse = $this->createVariant(
            productId: $this->productResponse['id'],
            warehouseData: $this->warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];
    }

    public function testCreateBundle(): void
    {
        $bundleData = [
            'name' => fake()->name,
            'description' => fake()->text,
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 2,
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
                            variant {
                                id
                            }
                        }
                    }
                }
            }',
            ['data' => $bundleData]
        );

        $response->assertJsonStructure([
            'data' => [
                'createBundle' => [
                    'id',
                    'name',
                    'description',
                    'execution_mode',
                    'expose_as_product',
                    'bundleItems' => [
                        'data' => [
                            '*' => [
                                'quantity',
                                'unit',
                                'variant' => [
                                    'id',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonPath('data.createBundle.name', $bundleData['name'])
        ->assertJsonPath('data.createBundle.description', $bundleData['description'])
        ->assertJsonPath('data.createBundle.execution_mode', $bundleData['execution_mode'])
        ->assertJsonPath('data.createBundle.expose_as_product', $bundleData['expose_as_product'])
        ->assertJsonPath('data.createBundle.bundleItems.data.0.quantity', 2)
        ->assertJsonPath('data.createBundle.bundleItems.data.0.unit', 'unit');
    }

    public function testUpdateBundle(): void
    {
        // First create a bundle
        $bundleData = [
            'name' => fake()->name,
            'description' => fake()->text,
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 1,
                    'unit' => 'unit',
                ],
            ],
            'execution_mode' => 'manual',
            'expose_as_product' => false,
        ];

        $createResponse = $this->graphQL(
            '
            mutation($data: BundleInput!) {
                createBundle(input: $data) {
                    id
                }
            }',
            ['data' => $bundleData]
        );

        $bundleId = $createResponse->json()['data']['createBundle']['id'];

        // Update the bundle
        $updatedBundleData = [
            'name' => 'Updated Bundle Name',
            'description' => 'Updated description',
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 3,
                    'unit' => 'piece',
                ],
            ],
            'execution_mode' => 'automatic',
            'expose_as_product' => true,
        ];

        $response = $this->graphQL(
            '
            mutation($id: ID!, $data: BundleInput!) {
                updateBundle(id: $id, input: $data) {
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
            [
                'id' => $bundleId,
                'data' => $updatedBundleData,
            ]
        );

        $response->assertJsonStructure([
            'data' => [
                'updateBundle' => [
                    'id',
                    'name',
                    'description',
                    'execution_mode',
                    'expose_as_product',
                    'bundleItems' => [
                        'data' => [
                            '*' => [
                                'quantity',
                                'unit',
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonPath('data.updateBundle.id', $bundleId)
        ->assertJsonPath('data.updateBundle.name', $updatedBundleData['name'])
        ->assertJsonPath('data.updateBundle.description', $updatedBundleData['description'])
        ->assertJsonPath('data.updateBundle.execution_mode', $updatedBundleData['execution_mode'])
        ->assertJsonPath('data.updateBundle.expose_as_product', $updatedBundleData['expose_as_product'])
        ->assertJsonPath('data.updateBundle.bundleItems.data.0.quantity', 3)
        ->assertJsonPath('data.updateBundle.bundleItems.data.0.unit', 'piece');
    }

    public function testListBundles(): void
    {
        // Create multiple bundles
        $bundleData1 = [
            'name' => 'Bundle One',
            'description' => 'First bundle description',
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 1,
                    'unit' => 'unit',
                ],
            ],
            'execution_mode' => 'manual',
            'expose_as_product' => false,
        ];

        $bundleData2 = [
            'name' => 'Bundle Two',
            'description' => 'Second bundle description',
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 2,
                    'unit' => 'piece',
                ],
            ],
            'execution_mode' => 'automatic',
            'expose_as_product' => true,
        ];

        // Create first bundle
        $this->graphQL(
            '
            mutation($data: BundleInput!) {
                createBundle(input: $data) {
                    id
                }
            }',
            ['data' => $bundleData1]
        );

        // Create second bundle
        $this->graphQL(
            '
            mutation($data: BundleInput!) {
                createBundle(input: $data) {
                    id
                }
            }',
            ['data' => $bundleData2]
        );

        // List bundles
        $response = $this->graphQL(
            '
            query {
                bundles(first: 10) {
                    data {
                        id
                        name
                        description
                        execution_mode
                        expose_as_product
                        bundleItems(first: 10) {
                            data {
                                quantity
                                unit
                                variant {
                                    id
                                }
                            }
                        }
                    }
                    paginatorInfo {
                        count
                        currentPage
                        firstItem
                        hasMorePages
                        lastItem
                        lastPage
                        perPage
                        total
                    }
                }
            }'
        );

        $response->assertJsonStructure([
            'data' => [
                'bundles' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'execution_mode',
                            'expose_as_product',
                            'bundleItems' => [
                                'data' => [
                                    '*' => [
                                        'quantity',
                                        'unit',
                                        'variant' => [
                                            'id',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'paginatorInfo' => [
                        'count',
                        'currentPage',
                        'firstItem',
                        'hasMorePages',
                        'lastItem',
                        'lastPage',
                        'perPage',
                        'total',
                    ],
                ],
            ],
        ]);

        $bundlesData = $response->json()['data']['bundles']['data'];
        $this->assertGreaterThanOrEqual(2, count($bundlesData));

        // Find our created bundles
        $bundleNames = array_column($bundlesData, 'name');
        $this->assertContains('Bundle One', $bundleNames);
        $this->assertContains('Bundle Two', $bundleNames);
    }

    public function testListBundlesWithFilters(): void
    {
        // Create a bundle with specific name for filtering
        $bundleData = [
            'name' => 'Filtered Bundle Test',
            'description' => 'Bundle for filter testing',
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 1,
                    'unit' => 'unit',
                ],
            ],
            'execution_mode' => 'manual',
            'expose_as_product' => true,
        ];

        $this->graphQL(
            '
            mutation($data: BundleInput!) {
                createBundle(input: $data) {
                    id
                }
            }',
            ['data' => $bundleData]
        );

        // Test filtering by name
        $response = $this->graphQL(
            '
            query($where: QueryBundlesWhereWhereConditions) {
                bundles(where: $where, first: 10) {
                    data {
                        id
                        name
                        description
                        execution_mode
                        expose_as_product
                    }
                }
            }',
            [
                'where' => [
                    'column' => 'NAME',
                    'operator' => 'LIKE',
                    'value' => '%Filtered Bundle%',
                ],
            ]
        );

        $response->assertJsonStructure([
            'data' => [
                'bundles' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'execution_mode',
                            'expose_as_product',
                        ],
                    ],
                ],
            ],
        ]);

        $bundlesData = $response->json()['data']['bundles']['data'];
        $this->assertGreaterThan(0, count($bundlesData));

        foreach ($bundlesData as $bundle) {
            $this->assertStringContainsString('Filtered Bundle', $bundle['name']);
        }
    }

    public function testDeleteBundle(): void
    {
        // First create a bundle
        $bundleData = [
            'name' => fake()->name,
            'description' => fake()->text,
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 1,
                    'unit' => 'unit',
                ],
            ],
            'execution_mode' => 'manual',
            'expose_as_product' => false,
        ];

        $createResponse = $this->graphQL(
            '
            mutation($data: BundleInput!) {
                createBundle(input: $data) {
                    id
                }
            }',
            ['data' => $bundleData]
        );

        $bundleId = $createResponse->json()['data']['createBundle']['id'];

        // Delete the bundle
        $response = $this->graphQL(
            '
            mutation($id: ID!) {
                deleteBundle(id: $id)
            }',
            ['id' => $bundleId]
        );

        $response->assertJsonStructure([
            'data' => [
                'deleteBundle',
            ],
        ])
        ->assertJsonPath('data.deleteBundle', true);

        // Verify the bundle is deleted by trying to query it in the list
        $listResponse = $this->graphQL(
            '
            query($where: QueryBundlesWhereWhereConditions) {
                bundles(where: $where, first: 10) {
                    data {
                        id
                    }
                }
            }',
            [
                'where' => [
                    'column' => 'ID',
                    'operator' => 'EQ',
                    'value' => $bundleId,
                ],
            ]
        );

        $bundlesData = $listResponse->json()['data']['bundles']['data'];
        $this->assertCount(0, $bundlesData);
    }

    public function testCreateBundleWithMultipleVariants(): void
    {
        // Create another variant for testing multiple variants in a bundle
        $secondVariantResponse = $this->createVariant(
            productId: $this->productResponse['id'],
            warehouseData: $this->warehouseData,
            attributes: [
                [
                    'name' => 'color',
                    'value' => 'blue',
                ],
            ]
        )->json()['data']['createVariant'];

        $bundleData = [
            'name' => 'Multi-Variant Bundle',
            'description' => 'Bundle with multiple variants',
            'variants' => [
                [
                    'id' => $this->variantResponse['id'],
                    'quantity' => 1,
                    'unit' => 'piece',
                ],
                [
                    'id' => $secondVariantResponse['id'],
                    'quantity' => 2,
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
                    bundleItems(first: 10) {
                        data {
                            quantity
                            unit
                            variant {
                                id
                            }
                        }
                    }
                }
            }',
            ['data' => $bundleData]
        );

        $response->assertJsonStructure([
            'data' => [
                'createBundle' => [
                    'id',
                    'name',
                    'description',
                    'bundleItems' => [
                        'data' => [
                            '*' => [
                                'quantity',
                                'unit',
                                'variant' => [
                                    'id',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonPath('data.createBundle.name', $bundleData['name']);

        $bundleItems = $response->json()['data']['createBundle']['bundleItems']['data'];
        $this->assertCount(2, $bundleItems);

        // Check that both variants are present
        $variantIds = array_column(array_column($bundleItems, 'variant'), 'id');
        $this->assertContains($this->variantResponse['id'], $variantIds);
        $this->assertContains($secondVariantResponse['id'], $variantIds);
    }
}

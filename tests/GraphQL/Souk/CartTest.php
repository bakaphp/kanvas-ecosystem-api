<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class CartTest extends TestCase
{
    use InventoryCases;

    private function createTestVariantWarehouse(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
        )->json()['data']['createVariant'];

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 10
        );

        $variant = Variants::find($variantResponse['id']);

        return [
            'variant' => $variant,
            'variant_id' => $variantResponse['id'],
            'company' => $company,
            'warehouse_id' => $warehouseResponse['id'],
        ];
    }

    public function testAddToCart(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];

        $this->app['auth']->forgetGuards();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                        id
                        quantity
                    }
                }
        ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => Str::uuid(),
            ],
        )->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'addToCart' => [
                    ['id', 'quantity'],
                ],
            ],
        ]);
    }

    public function testUpdateCart(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];
        $uuid = Str::uuid();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                        id
                        quantity
                    }
                }
        ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        );
        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($variant_id: ID!, $quantity: Int!) {
                updateCart(variant_id: $variant_id, quantity: $quantity) {
                        id
                        quantity
                    }
                },
            ',
            [
                'variant_id' => $testData['variant_id'],
                'quantity' => 1,
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertSuccessful();
    }

    public function testRemoveFromCart(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];
        $uuid = Str::uuid();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                        id
                        quantity
                    }
                }
        ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        );
        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($variant_id: ID!) {
                removeFromCart(variant_id: $variant_id) {
                        id
                        quantity
                    }
                },
            ',
            [
                'variant_id' => $testData['variant_id'],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertJson([
            'data' => [
                'removeFromCart' => [
                ],
            ],
        ]);
    }

    public function testCart(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];
        $uuid = Str::uuid();

        $this->app['auth']->forgetGuards();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                        id
                        quantity
                    }
                }
        ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        );
        $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                cart {
                    items {
                        id
                        quantity
                    }
                }
            }
        ',
            [],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'cart' => [
                    'items' => [
                        ['id', 'quantity'],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateCartAttributes(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];
        $uuid = Str::uuid();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                    quantity
                    attributes
                }
            }
            ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                        'attributes' => [
                            'color' => 'red',
                            'size' => 'large',
                        ],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        );

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($variant_id: ID!, $quantity: Int!, $attributes: Mixed) {
                updateCart(variant_id: $variant_id, quantity: $quantity, attributes: $attributes) {
                    id
                    quantity
                    attributes
                }
            }
            ',
            [
                'variant_id' => (string) $testData['variant_id'],
                'quantity' => 2,
                'attributes' => [
                    'size' => 'medium',
                    'material' => 'cotton',
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertSuccessful();
    }

    public function testUpdateCartQuantityOnly(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];
        $uuid = Str::uuid();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                    quantity
                    attributes
                }
            }
            ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        );

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($variant_id: ID!, $quantity: Int!) {
                updateCart(variant_id: $variant_id, quantity: $quantity) {
                    id
                    quantity
                    attributes
                }
            }
            ',
            [
                'variant_id' => (string) $testData['variant_id'],
                'quantity' => 2,
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertSuccessful();
    }

    public function testUpdateCartClearAttributes(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $company = $testData['company'];
        $uuid = Str::uuid();

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                id
                quantity
                attributes
            }
        }
            ',
            [
                'items' => [
                    [
                        'variant_id' => $testData['variant_id'],
                        'quantity' => 1,
                        'warehouse_id' => $testData['warehouse_id'],
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        );

        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation($variant_id: ID!, $quantity: Int!, $attributes: Mixed) {
                updateCart(variant_id: $variant_id, quantity: $quantity, attributes: $attributes) {
                id
                quantity
                attributes
            }
        }
            ',
            [
                'variant_id' => (string) $testData['variant_id'],
                'quantity' => 1,
                'attributes' => [],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertSuccessful();
    }
}

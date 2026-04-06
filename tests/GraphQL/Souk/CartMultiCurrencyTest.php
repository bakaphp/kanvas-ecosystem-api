<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class CartMultiCurrencyTest extends TestCase
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
            'company' => $company,
            'warehouse_id' => $warehouseResponse['id'],
        ];
    }

    public function testAddToCartReturnsCurrency(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $variant = $testData['variant'];
        $company = $testData['company'];

        $this->app['auth']->forgetGuards();

        $response = $this->graphQL(
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
                        'variant_id' => $variant->getId(),
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
        );

        $cartItem = $response->json('data.addToCart.0');
        $this->assertArrayHasKey('currency', $cartItem['attributes']);
        $this->assertArrayHasKey('code', $cartItem['attributes']['currency']);
        $this->assertArrayHasKey('symbol', $cartItem['attributes']['currency']);
    }

    public function testAddToCartWithoutWarehouseIdReturnsCurrency(): void
    {
        $testData = $this->createTestVariantWarehouse();
        $variant = $testData['variant'];
        $company = $testData['company'];

        $this->app['auth']->forgetGuards();

        $response = $this->graphQL(
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
                        'variant_id' => $variant->getId(),
                        'quantity' => 1,
                    ],
                ],
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => Str::uuid(),
            ],
        );

        $cartItem = $response->json('data.addToCart.0');
        $this->assertArrayHasKey('currency', $cartItem['attributes']);
    }
}

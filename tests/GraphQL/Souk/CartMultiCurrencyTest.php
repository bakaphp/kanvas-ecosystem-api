<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\TestCase;

class CartMultiCurrencyTest extends TestCase
{
    public function testAddToCartReturnsCurrency(): void
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;

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
                        'variant_id' => $variantWarehouse->products_variants_id,
                        'quantity' => 1,
                        'warehouse_id' => $variantWarehouse->warehouses_id,
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
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;

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
                        'variant_id' => $variantWarehouse->products_variants_id,
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

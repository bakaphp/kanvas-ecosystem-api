<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\TestCase;

class CartTest extends TestCase
{
    public function testAddToCart(): void
    {
        $variantWarehouse = VariantsWarehouses::first();

        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;

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
        )->assertJson([
            'data' => [
                'addToCart' => [
                    [
                        'id' => $variantWarehouse->products_variants_id,
                        'quantity' => 1,
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateCart(): void
    {
        $variantWarehouse = VariantsWarehouses::first();

        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
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
                        'variant_id' => $variantWarehouse->products_variants_id,
                        'quantity' => 1,
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
                'variant_id' => $variantWarehouse->products_variants_id,
                'quantity' => 1,
            ],
            [],
            [
                'X-Kanvas-Location' => $company->branch->uuid,
                'X-Kanvas-Identifier' => $uuid,
            ],
        )->assertJson([
            'data' => [
                'updateCart' => [
                    [
                        'id' => $variantWarehouse->products_variants_id,
                        'quantity' => 2,
                    ],
                ],
            ],
        ]);
    }

    public function testRemoveFromCart(): void
    {
        $variantWarehouse = VariantsWarehouses::first();

        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
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
                        'variant_id' => $variantWarehouse->products_variants_id,
                        'quantity' => 1,
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
                'variant_id' => $variantWarehouse->products_variants_id,
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
}

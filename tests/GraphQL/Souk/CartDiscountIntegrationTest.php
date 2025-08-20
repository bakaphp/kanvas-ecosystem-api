<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Tests\TestCase;

class CartDiscountIntegrationTest extends TestCase
{
    public function testApplyDiscountToCart(): void
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $uuid = Str::uuid();
        $user = auth()->user();
        $app = app(Apps::class);

        //$this->app['auth']->forgetGuards();

        // Create a discount type
        $discountType = DiscountType::firstOrCreate([
            'name' => 'Percentage',
        ], [
            'description' => 'Percentage discount',
        ]);

        $discountFactory = Discount::fromCompany($company)->fromApp($app)->where('code', 'TESTCART10')->first();

        if (! $discountFactory) {
            $discountFactory = Discount::factory()->withCompanyId($company->id)->create([
                'name' => 'Test Cart Discount',
                'description' => 'Test Cart Discount',
                'code' => 'TESTCART10',
                'discount_type_id' => $discountType->id,
                'companies_id' => $company->id,
                'value' => 10,
                'is_percentage' => true,
                'is_active' => true,
                'min_order_value' => 0,
            ]);
        }

        $discountId = $discountFactory->id;
        $discountCode = $discountFactory->code;

        // Add item to cart
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                cartAdd(items: $items) {
                    id
                    name
                    price
                    quantity
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variantWarehouse->products_variants_id,
                    'quantity' => 1,
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        // Apply discount code using cartDiscountCodesUpdate
        $response = $this->graphQL('
            mutation applyDiscount($discountCodes: [String!]!) {
                cartDiscountCodesUpdate(discountCodes: $discountCodes) {
                    items {
                        id
                        name
                        price
                        quantity
                    }
                    subtotal
                    total
                   
                }
            }
        ', [
            'discountCodes' => [$discountCode],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        $response->assertJsonStructure([
            'data' => [
                'cartDiscountCodesUpdate' => [
                    'items',
                    'subtotal',
                    'total',
                ],
            ],
        ]);

        // Verify total is discounted
        $subtotal = $response->json('data.cartDiscountCodesUpdate.subtotal');
        $total = $response->json('data.cartDiscountCodesUpdate.total');

        // Subtotal should be the original price
        $expectedSubtotal = $variantWarehouse->price;
        $expectedTotal = $expectedSubtotal * 0.9; // 10% discount

        $this->assertEquals($expectedSubtotal, $subtotal);
        $this->assertEquals($expectedTotal, $total); // 10% discount applied
    }

    public function testInvalidDiscountCode(): void
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $uuid = Str::uuid();

        $this->app['auth']->forgetGuards();

        // First add an item to cart
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                cartAdd(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variantWarehouse->products_variants_id,
                    'quantity' => 1,
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        // Try to apply non-existent discount code
        $response = $this->graphQL('
            mutation applyDiscount($discountCodes: [String!]!) {
                cartDiscountCodesUpdate(discountCodes: $discountCodes) {
                    total
                }
            }
        ', [
            'discountCodes' => ['INVALID_CODE'],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        $response->assertJsonStructure([
            'errors' => [
                '*' => ['message'],
            ],
        ]);

        $this->assertStringContainsString('Discount code not found', $response->json('errors.0.message'));
    }

    public function testMinimumOrderValueValidation(): void
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $uuid = Str::uuid();

        $this->app['auth']->forgetGuards();

        // Create a discount with minimum order value
        $discountType = DiscountType::firstOrCreate([
            'name' => 'Fixed Amount',
        ], [
            'description' => 'Fixed amount discount',
        ]);

        $app = app(Apps::class);

        $discountFactory = Discount::fromCompany($company)->fromApp($app)->where('code', 'MIN100')->first();

        if (! $discountFactory) {
            $discountFactory = Discount::factory()->withCompanyId($company->id)->create([
            'name' => 'Min Order Discount',
            'description' => 'Min Order Discount',
            'code' => 'MIN100',
            'discount_type_id' => $discountType->id,
            'companies_id' => $company->id,
            'value' => 20,
            'is_percentage' => false,
            'is_active' => true,
            'min_order_value' => 999999, // Very high minimum that won't be met
            ]);
        }

        $discountCode = $discountFactory->code;

        // Add to cart
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                cartAdd(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variantWarehouse->products_variants_id,
                    'quantity' => 1,
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        // Try to apply discount using cartDiscountCodesUpdate
        $response = $this->graphQL('
            mutation applyDiscount($discountCodes: [String!]!) {
                cartDiscountCodesUpdate(discountCodes: $discountCodes) {
                    total
                }
            }
        ', [
            'discountCodes' => [$discountCode],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        $response->assertJsonStructure([
            'errors' => [
                '*' => ['message'],
            ],
        ]);

        $this->assertStringContainsString('Minimum order value', $response->json('errors.0.message'));
    }

    public function testDiscountSavedWithOrder(): void
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $uuid = Str::uuid();
        $app = app(Apps::class);

        $this->app['auth']->forgetGuards();

        // Create a discount type
        $discountType = DiscountType::firstOrCreate([
            'name' => 'Percentage',
        ], [
            'description' => 'Percentage discount',
        ]);

        $discountFactory = Discount::fromCompany($company)->fromApp($app)->where('code', 'ORDERTEST15')->first();

        if (! $discountFactory) {
            $discountFactory = Discount::factory()->withCompanyId($company->id)->create([
            'name' => 'Order Test Discount',
            'description' => 'Order Test Discount',
            'code' => 'ORDERTEST15',
            'discount_type_id' => $discountType->id,
            'companies_id' => $company->id,
            'value' => 15,
            'is_percentage' => true,
            'is_active' => true,
            ]);
        }

        $discountId = $discountFactory->id;
        $discountCode = $discountFactory->code;

        // Add item to cart
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                cartAdd(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variantWarehouse->products_variants_id,
                    'quantity' => 1,
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        // Apply discount code
        $cartResponse = $this->graphQL('
            mutation applyDiscount($discountCodes: [String!]!) {
                cartDiscountCodesUpdate(discountCodes: $discountCodes) {
                    subtotal
                    total
                }
            }
        ', [
            'discountCodes' => [$discountCode],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        // Verify discount was applied to cart
        $expectedSubtotal = $variantWarehouse->price;
        $expectedTotal = $expectedSubtotal * 0.85; // 15% off

        $this->assertEquals($expectedSubtotal, $cartResponse->json('data.cartDiscountCodesUpdate.subtotal'));
        $this->assertEquals($expectedTotal, $cartResponse->json('data.cartDiscountCodesUpdate.total'));

        // Now create an order from the cart
        // This would typically be done through createOrder mutation
        // The CreateBaseOrderAction should automatically save the discount from cart conditions
    }
}

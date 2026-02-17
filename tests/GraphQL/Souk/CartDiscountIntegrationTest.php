<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Referrals\Actions\GenerateReferralCodeAction;
use Tests\TestCase;

class CartDiscountIntegrationTest extends TestCase
{
    protected VariantsWarehouses $variantWarehouse;
    protected Variants $variant;
    protected $company;
    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $user = auth()->user();
        $this->company = $user->getCurrentCompany();

        $inventorySetup = new InventorySetup($this->kanvasApp, $user, $this->company);
        $inventorySetup->run();

        $product = Products::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();

        $this->variant = $product->variants()->first();

        $warehouse = Warehouses::getDefault($this->company, $this->kanvasApp);
        $channel = Channels::getDefault($this->company, $this->kanvasApp);

        $this->variantWarehouse = new AddToWarehouseAction(
            $this->variant,
            $warehouse,
            VariantsWarehousesDto::from([
                'variant' => $this->variant,
                'warehouse' => $warehouse,
                'quantity' => 10,
                'price' => 100,
                'sku' => $this->variant->sku,
            ])
        )->execute();

        VariantsChannels::updateOrCreate([
            'product_variants_warehouse_id' => $this->variantWarehouse->id,
            'channels_id' => $channel->getId(),
        ], [
            'products_variants_id' => $this->variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'price' => 100,
            'discounted_price' => 0,
            'is_published' => true,
        ]);
    }

    private function createDiscount(
        string $code,
        float $value,
        bool $isPercentage,
        array $extra = []
    ): Discount {
        $discountType = DiscountType::firstOrCreate([
            'name' => $isPercentage ? 'Percentage' : 'Fixed Amount',
        ], [
            'description' => $isPercentage ? 'Percentage discount' : 'Fixed amount discount',
        ]);

        return Discount::fromCompany($this->company)->fromApp($this->kanvasApp)->where('code', $code)->first()
            ?? Discount::factory()->withCompanyId($this->company->id)->create(array_merge([
                'name' => $code,
                'description' => $code,
                'code' => $code,
                'discount_type_id' => $discountType->id,
                'companies_id' => $this->company->id,
                'value' => $value,
                'is_percentage' => $isPercentage,
                'is_active' => true,
            ], $extra));
    }

    private function addItemToCart(string $uuid): void
    {
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $this->variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);
    }

    private function applyDiscountToCart(
        string $uuid,
        array $discountCodes,
        array $fields = ['subtotal', 'total']
    ) {
        $fieldSelection = implode("\n                    ", $fields);

        return $this->graphQL("
            mutation applyDiscount(\$discountCodes: [String!]!) {
                cartDiscountCodesUpdate(discountCodes: \$discountCodes) {
                    {$fieldSelection}
                }
            }
        ", [
            'discountCodes' => $discountCodes,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);
    }

    public function testApplyDiscountToCart(): void
    {
        $uuid = (string) Str::uuid();
        $discount = $this->createDiscount(
            'TESTCART10',
            10,
            true,
            ['min_order_value' => 0]
        );

        $this->addItemToCart($uuid);

        $response = $this->applyDiscountToCart($uuid, [$discount->code], [
            'items { id name price quantity }',
            'subtotal',
            'total',
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

        $subtotal = (float) $response->json('data.cartDiscountCodesUpdate.subtotal');
        $total = (float) $response->json('data.cartDiscountCodesUpdate.total');

        $this->assertGreaterThan(0, $subtotal);
        $expectedTotal = round($subtotal * 0.9, 2); // 10% discount
        $this->assertEquals($expectedTotal, $total);
    }

    public function testInvalidDiscountCode(): void
    {
        $uuid = (string) Str::uuid();

        $this->addItemToCart($uuid);

        $response = $this->applyDiscountToCart(
            $uuid,
            ['INVALID_CODE'],
            ['total']
        );

        $response->assertJsonStructure([
            'errors' => [
                '*' => ['message'],
            ],
        ]);

        $this->assertStringContainsString('Discount code not found', $response->json('errors.0.message'));
    }

    public function testMinimumOrderValueValidation(): void
    {
        $uuid = (string) Str::uuid();
        $discount = $this->createDiscount(
            'MIN100',
            20,
            false,
            ['min_order_value' => 999999]
        );

        $this->addItemToCart($uuid);

        $response = $this->applyDiscountToCart(
            $uuid,
            [$discount->code],
            ['total']
        );

        $response->assertJsonStructure([
            'errors' => [
                '*' => ['message'],
            ],
        ]);

        $this->assertStringContainsString('Minimum order value', $response->json('errors.0.message'));
    }

    public function testDiscountSavedWithOrder(): void
    {
        $uuid = (string) Str::uuid();
        $discount = $this->createDiscount(
            'ORDERTEST15',
            15,
            true
        );

        $this->addItemToCart($uuid);

        $cartResponse = $this->applyDiscountToCart(
            $uuid,
            [$discount->code],
            ['subtotal', 'total']
        );

        $subtotal = (float) $cartResponse->json('data.cartDiscountCodesUpdate.subtotal');
        $total = (float) $cartResponse->json('data.cartDiscountCodesUpdate.total');

        $this->assertGreaterThan(0, $subtotal);
        $expectedTotal = round($subtotal * 0.85, 2); // 15% off
        $this->assertEquals($expectedTotal, $total);
    }

    public function testRejectsOwnReferralCodeAsDiscount(): void
    {
        $uuid = (string) Str::uuid();
        $user = auth()->user();

        $loyaltyProgram = LoyaltyProgram::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
        ]);

        $referralCode = new GenerateReferralCodeAction(
            $user,
            $loyaltyProgram,
            $this->kanvasApp,
        )->execute();

        $this->addItemToCart($uuid);

        $response = $this->applyDiscountToCart(
            $uuid,
            [$referralCode->code],
            ['total']
        );

        $response->assertJsonStructure([
            'errors' => [
                '*' => ['message'],
            ],
        ]);

        $this->assertStringContainsString(
            'Discount code not found',
            $response->json('errors.0.message')
        );
    }
}

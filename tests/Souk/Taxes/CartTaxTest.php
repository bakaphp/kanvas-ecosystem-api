<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxConditionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Wearepixel\Cart\Cart;
use Wearepixel\Cart\CartCondition;

#[Group('serial')]
final class CartTaxTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private VariantsWarehouses $variantWarehouse;
    private Variants $variant;
    private $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);

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

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        parent::tearDown();
    }

    private function makeCart(string $sessionKey): Cart
    {
        $cart = app('cart')->session($sessionKey);
        $cart->clear();
        $cart->clearAllConditions();

        return $cart;
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

    private function createDiscount(string $code, float $value, bool $isPercentage): Discount
    {
        $discountType = DiscountType::firstOrCreate(
            ['name' => $isPercentage ? 'Percentage' : 'Fixed Amount'],
            ['description' => $isPercentage ? 'Percentage discount' : 'Fixed amount discount'],
        );

        return Discount::fromCompany($this->company)->fromApp($this->kanvasApp)->where('code', $code)->first()
            ?? Discount::factory()->withCompanyId($this->company->id)->create([
                'name' => $code,
                'description' => $code,
                'code' => $code,
                'discount_type_id' => $discountType->id,
                'companies_id' => $this->company->id,
                'value' => $value,
                'is_percentage' => $isPercentage,
                'is_active' => true,
                'start_date' => now()->subDay(),
                'end_date' => now()->addMonth(),
                'max_discount_amount' => null,
                'usage_limit' => null,
                'min_order_value' => 0,
            ]);
    }

    public function testTaxAppearsInCartAfterEstimate(): void
    {
        $sessionKey = 'tax-appear-' . uniqid();
        $cart = $this->makeCart($sessionKey);

        $cart->add([
            'id' => $this->variant->getId(),
            'name' => $this->variant->name,
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        $cart->condition(new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+10.00',
            'order' => 40,
            'attributes' => [
                'provider' => 'test',
                'destination' => [
                    'country' => 'DO',
                    'city' => 'Santo Domingo',
                    'postal_code' => null,
                ],
            ],
        ]));

        $cart->condition(new CartCondition([
            'name' => TaxConditionEnum::NAME->value,
            'type' => TaxConditionEnum::TYPE->value,
            'target' => 'total',
            'value' => '+18.00',
            'order' => 50,
            'attributes' => [
                'status' => 'calculated',
                'provider' => 'manual',
                'amount' => 18.00,
                'mode' => 'exclusive',
                'components' => [
                    [
                        'line_id' => (string) $this->variant->getId(),
                        'rate' => 0.18,
                        'amount' => 18.00,
                        'treatment' => 'taxable',
                    ],
                ],
            ],
        ]));

        $cartService = new CartService($cart);
        $result = $cartService->getCart();

        $this->assertArrayHasKey('tax', $result);
        $this->assertArrayHasKey('total_tax', $result);
        $this->assertSame('calculated', $result['tax']['status']);
        $this->assertGreaterThan(0.0, (float) $result['total_tax']);
    }

    public function testTaxUpdatesWhenDiscountApplied(): void
    {
        $uuid = (string) Str::uuid();
        $discount = $this->createDiscount('TAXDISCOUNT10', 10, true);

        $this->addItemToCart($uuid);

        $response = $this->graphQL('
            mutation applyDiscount($discountCodes: [String!]!) {
                cartDiscountCodesUpdate(discountCodes: $discountCodes) {
                    subtotal
                    total
                    total_discount
                    total_tax
                }
            }
        ', [
            'discountCodes' => [$discount->code],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-Identifier' => $uuid,
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.cartDiscountCodesUpdate');

        $this->assertNotNull($data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('total_discount', $data);
        $this->assertArrayHasKey('total_tax', $data);
        $this->assertGreaterThan(0, (float) $data['total_discount']);
        $this->assertNotNull($data['total_tax']);
    }

    public function testTaxFieldExcludedFromDiscountTotal(): void
    {
        $sessionKey = 'tax-exclude-discount-' . uniqid();
        $cart = $this->makeCart($sessionKey);

        $cart->add([
            'id' => $this->variant->getId(),
            'name' => $this->variant->name,
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        $cart->condition(new CartCondition([
            'name' => 'SAVE10',
            'type' => 'discount',
            'target' => 'subtotal',
            'value' => '-10.00',
            'order' => 1,
            'attributes' => [],
        ]));

        $cart->condition(new CartCondition([
            'name' => TaxConditionEnum::NAME->value,
            'type' => TaxConditionEnum::TYPE->value,
            'target' => 'total',
            'value' => '+18.00',
            'order' => 50,
            'attributes' => [
                'status' => 'calculated',
                'provider' => 'manual',
                'amount' => 18.00,
                'mode' => 'exclusive',
                'components' => [
                    [
                        'line_id' => (string) $this->variant->getId(),
                        'rate' => 0.18,
                        'amount' => 18.00,
                        'treatment' => 'taxable',
                    ],
                ],
            ],
        ]));

        $cartService = new CartService($cart);
        $result = $cartService->getCart();

        $this->assertArrayHasKey('discounts', $result);
        $discountCodes = array_column($result['discounts'], 'code');
        $this->assertNotContains(TaxConditionEnum::NAME->value, $discountCodes);
        $this->assertContains('SAVE10', $discountCodes);

        $this->assertGreaterThan(0.0, (float) $result['total_tax']);
        $this->assertNotEquals($result['total_discount'], $result['total_tax']);
    }
}

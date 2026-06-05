<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\GetSlotAvailabilityAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ProductSlotAvailabilityTest extends TestCase
{
    use InventoryCases;

    protected Apps $apps;
    protected mixed $company;
    protected mixed $user;
    protected mixed $region;
    protected array $warehouseResponse;
    protected array $channelResponse;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse = $this->createChannel()->json()['data']['createChannel'];

        // Ensure the movipass order type exists and is marked expirable before every test
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate(
            [
                'name' => 'movipass',
                'apps_id' => $this->apps->id,
                'companies_id' => $this->company->id,
            ]
        ));

        if (! $orderType->isExpirable()) {
            $orderType->config = ['expirable' => true];
            $orderType->save();
        }
    }

    public function testGetSlotAvailabilityUnlimited(): void
    {
        // Variant with max_capacity = 0 (DTO default, unconfigured) → unlimited / no slot tracking
        // GetSlotAvailabilityAction treats 0 and null both as "unlimited"
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 10,
        );

        $variant = Variants::find($variantResponse['id']);

        // max_capacity defaults to 0 (not configured) — treat as unlimited
        $this->assertEquals(0, $variant->variantWarehouses()->first()?->max_capacity);

        // Action returns CapacityStats with 0s when unconfigured (unlimited / no slot tracking)
        $slotData = new GetSlotAvailabilityAction($variant, $this->apps)->execute();
        $this->assertEquals(0, $slotData->maxCapacity);
        $this->assertEquals(0, $slotData->availableCapacity);
    }

    public function testGetSlotAvailabilityWithCapacity(): void
    {
        // max_capacity = 5, 2 active movipass orders → available = 3
        $variant = $this->buildMovipassVariantWithCapacity(5);

        $activeEndAt = ['data' => ['end_at' => now()->addHours(2)->toDateTimeString()]];
        $this->createMovipassOrder($variant, $activeEndAt);
        $this->createMovipassOrder($variant, $activeEndAt);

        $slotData = new GetSlotAvailabilityAction($variant, $this->apps)->execute();

        $this->assertEquals(5, $slotData->maxCapacity);
        $this->assertEquals(2, $slotData->occupiedCapacity);
        $this->assertEquals(3, $slotData->availableCapacity);
    }

    public function testGetSlotAvailabilityFullyBooked(): void
    {
        // max_capacity = 3, 3 active orders → available = 0
        $variant = $this->buildMovipassVariantWithCapacity(3);

        $activeEndAt = ['data' => ['end_at' => now()->addHours(2)->toDateTimeString()]];
        $this->createMovipassOrder($variant, $activeEndAt);
        $this->createMovipassOrder($variant, $activeEndAt);
        $this->createMovipassOrder($variant, $activeEndAt);

        $slotData = new GetSlotAvailabilityAction($variant, $this->apps)->execute();

        $this->assertEquals(3, $slotData->maxCapacity);
        $this->assertEquals(3, $slotData->occupiedCapacity);
        $this->assertEquals(0, $slotData->availableCapacity);
    }

    public function testExpiredOrdersDoNotCountAsActive(): void
    {
        // Expired orders (end_at in past) must not reduce available slots
        $variant = $this->buildMovipassVariantWithCapacity(5);

        $expiredEndAt = ['data' => ['end_at' => now()->subHours(6)->toDateTimeString()]];
        $this->createMovipassOrder($variant, $expiredEndAt);
        $this->createMovipassOrder($variant, $expiredEndAt);

        $slotData = new GetSlotAvailabilityAction($variant, $this->apps)->execute();

        $this->assertEquals(5, $slotData->maxCapacity);
        $this->assertEquals(0, $slotData->occupiedCapacity); // expired do not count
        $this->assertEquals(5, $slotData->availableCapacity);
    }

    public function testOrderCreationBlockedWhenFull(): void
    {
        // max_capacity = 1, 1 active order → slot full → next creation must be rejected
        $variant = $this->buildMovipassVariantWithCapacity(1);

        $activeEndAt = ['data' => ['end_at' => now()->addHours(2)->toDateTimeString()]];
        $this->createMovipassOrder($variant, $activeEndAt);

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                    message
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'customer' => ['email' => fake()->email()],
                'order_type' => 'movipass',
                'items' => [
                    [
                        'variant_id' => $variant->getId(),
                        'quantity' => 60,
                    ],
                ],
                'metadata' => [
                    'data' => [
                        'start_at' => now()->addHour()->toDateTimeString(),
                        'end_at' => now()->addHours(2)->toDateTimeString(),
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertArrayHasKey('errors', $response->json());
        $this->assertStringContainsString('No available slots', $response->json('errors.0.message'));
    }

    public function testOrderItemQuantityIsBillableMinutes(): void
    {
        // order_items.quantity must store billable minutes, not a slot-count of 1
        $variant = $this->buildMovipassVariantWithCapacity(10);
        $billableMinutes = 120;

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                    message
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'customer' => ['email' => fake()->email()],
                'order_type' => 'movipass',
                'items' => [
                    [
                        'variant_id' => $variant->getId(),
                        'quantity' => $billableMinutes,
                    ],
                ],
                'metadata' => [
                    'data' => [
                        'start_at' => now()->addHour()->toDateTimeString(),
                        'end_at' => now()->addHours(3)->toDateTimeString(),
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $orderId = $response->json('data.createOrderFromCart.order.id');
        $this->assertNotNull($orderId);

        $order = Order::findOrFail($orderId);
        $orderItem = $order->items->first();
        $this->assertEquals($billableMinutes, (int) $orderItem->quantity);
    }

    // -------------------------------------------------------------------------
    // Private helpers — mirror the pattern in OrderExpirableTest
    // -------------------------------------------------------------------------

    private function buildMovipassVariantWithCapacity(int $maxCapacity): Variants
    {
        $response = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => $maxCapacity],
        ]);
        $response->assertSuccessful();
        $productResponse = $response->json('data.createProduct');

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: $maxCapacity,
        );

        $variant = Variants::find($variantResponse['id']);
        $variantWarehouse = $variant->variantWarehouses()->first();
        $variantWarehouse->max_capacity = $maxCapacity;
        $variantWarehouse->save();

        return $variant->fresh();
    }

    private function createMovipassOrder(Variants $variant, array $endAtMetadata): Order
    {
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate(
            [
                'name' => 'movipass',
                'apps_id' => $this->apps->id,
                'companies_id' => $this->company->id,
            ]
        ));

        if (! $orderType->isExpirable()) {
            $orderType->config = ['expirable' => true];
            $orderType->save();
        }

        return Order::withoutSyncingToSearch(function () use ($variant, $orderType, $endAtMetadata) {
            $order = Order::create([
                'apps_id' => $this->apps->id,
                'companies_id' => $this->company->id,
                'order_types_id' => $orderType->id,
                'region_id' => $this->region->id,
                'users_id' => $this->user->id,
                'people_id' => 0,
                'status' => 'draft',
                'fulfillment_status' => 'pending',
                'metadata' => $endAtMetadata,
                'is_deleted' => 0,
            ]);

            $order->allItems()->create([
                'apps_id' => $this->apps->id,
                'product_name' => $variant->product->name,
                'product_sku' => $variant->sku,
                'quantity' => 1,
                'unit_price_net_amount' => 0,
                'unit_price_gross_amount' => 0,
                'is_shipping_required' => false,
                'quantity_fulfilled' => 0,
                'variant_id' => $variant->getId(),
                'variant_name' => $variant->name,
                'tax_rate' => 0,
                'currency' => 'USD',
                'is_public' => 1,
                'is_deleted' => 0,
            ]);

            return $order;
        });
    }
}

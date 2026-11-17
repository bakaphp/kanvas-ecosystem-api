<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\GetSlotAvailabilityAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use RuntimeException;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Concerns\BuildsSellableVariants;
use Tests\TestCase;

class ProductSlotAvailabilityTest extends TestCase
{
    use BuildsSellableVariants;
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

    public function testSlotLockIsHeldWhileOrderIsInserted(): void
    {
        $variant = $this->buildMovipassVariantWithCapacity(5);
        $lockWasBusyAtInsert = null;

        $this->listenForOrderInsert(function () use ($variant, &$lockWasBusyAtInsert) {
            $probe = Cache::lock('slot_availability:' . $variant->getId(), 5);
            $lockWasBusyAtInsert = ! $probe->get();

            if (! $lockWasBusyAtInsert) {
                $probe->release();
            }
        });

        $response = $this->createOrderFromCartResponse($variant);

        $this->assertNull($response->json('errors'));
        $this->assertNotNull($response->json('data.createOrderFromCart.order.id'));
        $this->assertTrue($lockWasBusyAtInsert);
        $this->assertSlotLockIsFree($variant);
    }

    public function testSlotLockIsReleasedWhenOrderInsertFails(): void
    {
        $variant = $this->buildMovipassVariantWithCapacity(5);

        $this->listenForOrderInsert(function () {
            throw new RuntimeException('insert failed');
        });

        $response = $this->createOrderFromCartResponse($variant);

        $this->assertArrayHasKey('errors', $response->json());
        $this->assertSlotLockIsFree($variant);
    }

    public function testSlotLockIsReleasedWhenCapacityIsFull(): void
    {
        $variant = $this->buildMovipassVariantWithCapacity(1);
        $this->createMovipassOrder($variant, ['data' => ['end_at' => now()->addHours(2)->toDateTimeString()]]);

        $response = $this->createOrderFromCartResponse($variant);

        $this->assertStringContainsString('No available slots', $response->json('errors.0.message'));
        $this->assertSlotLockIsFree($variant);
    }

    public function testUnlimitedVariantTakesNoSlotLock(): void
    {
        $variant = $this->buildMovipassVariantWithCapacity(0);
        $lockWasFreeAtInsert = null;

        $this->listenForOrderInsert(function () use ($variant, &$lockWasFreeAtInsert) {
            $probe = Cache::lock('slot_availability:' . $variant->getId(), 5);
            $lockWasFreeAtInsert = $probe->get();

            if ($lockWasFreeAtInsert) {
                $probe->release();
            }
        });

        $response = $this->createOrderFromCartResponse($variant);

        $this->assertNull($response->json('errors'));
        $this->assertTrue($lockWasFreeAtInsert);
    }

    public function testSecondSlotLockTimeoutReleasesTheFirstLock(): void
    {
        $firstVariant = $this->buildMovipassVariantWithCapacity(5);
        $secondVariant = $this->buildMovipassVariantWithCapacity(5);
        $heldLock = Cache::lock('slot_availability:' . $secondVariant->getId(), 60);
        $this->assertTrue($heldLock->get());

        $response = $this->createOrderFromCartResponse($secondVariant, $firstVariant);

        $heldLock->release();

        $this->assertStringContainsString('Could not verify slot availability', $response->json('errors.0.message'));
        $this->assertSlotLockIsFree($firstVariant);
        $this->assertSlotLockIsFree($secondVariant);
    }

    private function listenForOrderInsert(callable $listener): void
    {
        $event = 'eloquent.creating: ' . Order::class;

        Event::listen($event, function () use ($listener) {
            $listener();
        });

        $this->beforeApplicationDestroyed(fn () => Event::forget($event));
    }

    private function assertSlotLockIsFree(Variants $variant): void
    {
        $probe = Cache::lock('slot_availability:' . $variant->getId(), 5);

        $this->assertTrue($probe->get());
        $probe->release();
    }

    private function createOrderFromCartResponse(Variants ...$variants): TestResponse
    {
        return $this->graphQL('
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
                'items' => array_map(
                    fn (Variants $variant) => ['variant_id' => $variant->getId(), 'quantity' => 60],
                    $variants
                ),
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
    }

    private function buildMovipassVariantWithCapacity(int $maxCapacity): Variants
    {
        $variant = $this->buildSellableVariant(
            stock: $maxCapacity,
            productAttributes: [['name' => 'slots', 'value' => $maxCapacity]]
        );
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

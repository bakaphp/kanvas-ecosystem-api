<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\GenerateOrderLateFee;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderLateFeeTest extends TestCase
{
    use InventoryCases;

    protected $variant;
    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $warehouseResponse;
    protected $channelResponse;

    public function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse = $this->createChannel()->json()['data']['createChannel'];
    }

    public function createDraftOrder(
        array $metadata,
        string|int $variantId,
        int $quantity = 1,
    ): Order {
        $data = [
            'email' => fake()->email(),
            'region_id' => $this->region->getId(),
            'metadata' => $metadata,
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order = Order::find($response->json('data.createDraftOrder.id'));

        return $order;
    }

    public function testOrderLateFee(): void
    {
        Notification::fake();
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = 'America/New_York';
        Date::setTestNow(now()->startOfSecond());
        $rightNow = now($timezone);
        $today = now($timezone);
        $yesterday = now($timezone)->subDays(1);

        $reservation1 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow->subDays(2)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $rightNow->startOfDay()->addDays(1)->toDateTimeString(),
                ],
            ],
        );

        $reservation2 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'terms_and_conditions' => true,
                    'late_fee_charged_at' => null,
                    'note' => 'test',
                    'start_at' => $yesterday->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $yesterday->startOfDay()->addDays(1)->toDateTimeString(),
                ],
            ],
        );

        $reservation2->completed();
        $total = $reservation1->getTotalAmount();
        $totalItems = $reservation1->items;
        $reservation1->created_at = now()->subDays(2);
        $reservation1->save();

        $lateOrders = new GenerateOrderLateFee($this->apps)->execute($today->toDateTimeString(), [$reservation1->getId(), $reservation2->getId()]);
        $order = $reservation1->fresh();

        $this->assertCount(1, $totalItems);
        $this->assertEquals(1, $lateOrders->count());
        $this->assertCount(2, $order->items);
        $this->assertEquals($order->getTotalAmount(), $total + 100);
    }

    public function testOrderLateFeeWithMultipleLateFees(): void
    {
        Notification::fake();
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $this->apps->set(EnumsConfigurationEnum::GRACE_PERIOD_DAYS->value, '1');
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = 'America/New_York';
        Date::setTestNow(now()->startOfSecond());
        $rightNow = CarbonImmutable::now($timezone);

        $reservation1 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow->subDays(32)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $rightNow->subDays(31)->startOfDay()->toDateTimeString(),
                ],
            ],
        );

        $reservation2 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow->subDays(62)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $rightNow->subDays(61)->startOfDay()->toDateTimeString(),
                ],
            ],
        );

        $reservation3 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow->subDays(61)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $rightNow->subDays(60)->startOfDay()->toDateTimeString(),
                ],
            ],
        );

        $total = $reservation1->getTotalAmount();

        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $rightNow->toDateTimeString(),
                orderIds: [$reservation1->getId(), $reservation2->getId(), $reservation3->getId()],
                addLateFee: false,
            );

        // Capture the updated_at timestamp right before processing late fees
        $reservation1BeforeLateFee = $reservation1->fresh();
        $firstUpdatedAt = $reservation1BeforeLateFee->updated_at;

        sleep(1); // Ensure there's a time difference if updated_at changes
        Artisan::call('kanvas:movipass-charge-late-orders');
        $order = $reservation1->fresh();

        $this->assertCount(3, $lateOrders);
        $this->assertCount(2, $order->items);
        $this->assertEquals($total + 200, $order->getTotalAmount()); // 1 day + 30 days

        // Check that updated_at was NOT changed by late fee processing
        $this->assertEquals($firstUpdatedAt->getTimestamp(), $order->updated_at->getTimestamp(), 'The updated_at timestamp should not change when processing late fees');
        $this->assertEquals($total + 300, $reservation2->fresh()->getTotalAmount()); // 1 day + 60 days
        $this->assertEquals($total + 200, $reservation3->fresh()->getTotalAmount()); // 1 day + 59 days
    }

    public function testOrderLateFeeWithHolidays(): void
    {
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');

        // Configure company with holiday settings
        $this->company->set(\Kanvas\Companies\Enums\ConfigurationEnum::COUNTRY_CODE->value, 'DO');

        // Add special days: Christmas Eve as half-day, custom company day as full day
        $this->company->set(\Kanvas\Companies\Enums\ConfigurationEnum::SPECIAL_DAYS->value, [
            [
                'date' => "2025-12-25",
                'type' => 'full_day',
                'name' => 'Christmas',
            ],
        ]);

        // Create late fee product
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        // Create main product
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        // Setup channels and warehouses
        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = 'America/New_York';
        $today = CarbonImmutable::create(2025, 12, 26, 0, 0, 0, $timezone);
        Date::setTestNow($today->subDays(2)->startOfSecond());

        $order1 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $today->subDays(2)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $today->subDays(1)->startOfDay()->toDateTimeString(), // Dec 20
                ],
            ],
        );

        $order2 = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $today->subDays(3)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $today->subDays(2)->startOfDay()->toDateTimeString(), // Dec 23
                ],
            ],
        );

        $total = $order1->getTotalAmount();

        Date::setTestNow($today->startOfSecond());

        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $today->toDateTimeString(),
                orderIds: [$order1->getId(), $order2->getId()],
                addLateFee: true
            );

        // Verify results
        $reservation1 = $order1->fresh();
        $reservation2 = $order2->fresh();

        $this->assertCount(1, $lateOrders);
        $this->assertEquals(100, $reservation1->fresh()->getTotalAmount());
        $this->assertEquals($total + 100, $reservation2->fresh()->getTotalAmount());
    }

    public function testOrderLateFeeWithSpecialDaysAndWeekends(): void
    {
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');

        // Configure company with special days for Dec 24 and 25
        $this->company->set(\Kanvas\Companies\Enums\ConfigurationEnum::SPECIAL_DAYS->value, [
            [
                'date' => "2025-12-24",
                'type' => 'full_day',
                'name' => 'Christmas Eve',
            ],
            [
                'date' => "2025-12-25",
                'type' => 'full_day',
                'name' => 'Christmas',
            ],
        ]);

        // Create late fee product
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        // Create main product
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        // Setup channels and warehouses
        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = 'America/New_York';

        // Monday Dec 29, 2025 - checking late fees
        $monday = CarbonImmutable::create(2025, 12, 29, 0, 0, 0, $timezone);
        Date::setTestNow($monday->subDays(6)->startOfSecond());

        // Order with grace starting Dec 23 (Tuesday)
        // Expected calculation from Dec 23 to Dec 29:
        // Dec 23 (Tue): 0 retained (grace day)
        // Dec 24 (Wed): 0 (special day - Christmas Eve)
        // Dec 25 (Thu): 0 (special day - Christmas)
        // Dec 26 (Fri): 1 day
        // Dec 27 (Sat): 0.0 day
        // Dec 28 (Sun): 0 (Sunday skip)
        // Dec 29 (Mon): 0 (current day, not finished)
        // Total: 2.5 business days late
        $order = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $monday->subDays(6)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $monday->subDays(5)->startOfDay()->toDateTimeString(), // Dec 23
                ],
            ],
        );

        $originalTotal = $order->getTotalAmount();

        // Set test time to Monday Dec 27
        Date::setTestNow($monday->subDays(2)->startOfSecond());

        // Execute late fee calculation
        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $monday->subDays(2)->toDateTimeString(),
                orderIds: [$order->getId()],
                addLateFee: true
            );

        $orderFresh = $order->fresh();

        // Verify order is late
        $this->assertCount(1, $lateOrders);
        $this->assertArrayHasKey('business_days_late', $orderFresh->metadata['data']);
        $this->assertEquals(1, $orderFresh->metadata['data']['business_days_late']);

        // Set test time to Monday Dec 29
        Date::setTestNow($monday->startOfSecond());

        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $monday->toDateTimeString(),
                orderIds: [$order->getId()],
                addLateFee: true
            );

        $orderFresh = $order->fresh();

        // Verify order is late
        $this->assertCount(1, $lateOrders);
        $this->assertArrayHasKey('business_days_late', $orderFresh->metadata['data']);
        $this->assertEquals(1.5, $orderFresh->metadata['data']['business_days_late']);

        // Late fee should be added (2.5 days = 1 fee since ceil(2.5/30) = 1)
        $this->assertGreaterThan(1, $orderFresh->items()->count());
        $this->assertEquals($originalTotal + 100, $orderFresh->getTotalAmount());
    }

    public function testOrderLateFeeWithWeekendsOnly(): void
    {
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');

        // No special days configured - only weekend rules apply
        $this->company->set(\Kanvas\Companies\Enums\ConfigurationEnum::SPECIAL_DAYS->value, []);

        // Create late fee product
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        // Create main product
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        // Setup channels and warehouses
        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = 'America/New_York';

        // Monday Dec 29, 2025
        $monday = CarbonImmutable::create(2025, 12, 29, 0, 0, 0, $timezone);
        Date::setTestNow($monday->subDays(6)->startOfSecond());

        // Order with grace starting Dec 23 (Tuesday)
        // Expected calculation from Dec 23 to Dec 29 (no special days):
        // Dec 23 (Tue): 1 day
        // Dec 24 (Wed): 1 day
        // Dec 25 (Thu): 1 day
        // Dec 26 (Fri): 1 day
        // Dec 27 (Sat): 0.5 day
        // Dec 28 (Sun): 0 (Sunday skip)
        // Dec 29 (Mon): 0 (current day, not finished)
        // Total: 4.5 business days late
        $order = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $monday->subDays(8)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $monday->subDays(6)->startOfDay()->toDateTimeString(), // Dec 23
                ],
            ],
        );

        $originalTotal = $order->getTotalAmount();

        // Set test time to Monday Dec 29
        Date::setTestNow($monday->startOfSecond());

        // Execute late fee calculation
        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $monday->toDateTimeString(),
                orderIds: [$order->getId()],
                addLateFee: true
            );

        $orderFresh = $order->fresh();

        // Verify order is late
        $this->assertCount(1, $lateOrders);
        $this->assertArrayHasKey('business_days_late', $orderFresh->metadata['data']);
        $this->assertEquals(4.5, $orderFresh->metadata['data']['business_days_late']);

        // Late fee should be added (4.5 days = 1 fee since ceil(4.5/30) = 1)
        $this->assertGreaterThan(1, $orderFresh->items()->count());
        $this->assertEquals($originalTotal + 100, $orderFresh->getTotalAmount());
    }

    public function testOrderLateFeeWithTimezoneAndSpecialDay(): void
    {
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');

        // Configure company with special day for Juan Pablo Duarte's Birthday
        $this->company->set(\Kanvas\Companies\Enums\ConfigurationEnum::SPECIAL_DAYS->value, [
            ['date' => '2026-01-26', 'type' => 'full_day', 'name' => 'Día del Natalicio de Juan Pablo Duarte'],
        ]);

        // Create late fee product
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        // Create main product
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        // Setup channels and warehouses
        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        // Scenario: App/Company is in UTC-4 timezone
        // 2026-01-26 is a special day (Día del Natalicio de Juan Pablo Duarte)
        // Today is 2026-01-27 (Monday)
        $timezone = 'America/Santo_Domingo'; // UTC-4
        $today = CarbonImmutable::create(2026, 1, 27, 0, 0, 0, $timezone);
        Date::setTestNow($today->subDays(2)->startOfSecond());

        // Create order with grace period starting on the special day (2026-01-26)
        // Expected calculation from Jan 26 to Jan 27:
        // Jan 26 (Mon): 0 days (special day - Juan Pablo Duarte's Birthday)
        // Jan 27 (Tue): 0 days (current day, not finished)
        // Total: 0 business days late (should NOT charge late fee)
        $order = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $today->subDays(2)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $today->subDays(1)->startOfDay()->toDateTimeString(), // 2026-01-26
                ],
            ],
        );

        $originalTotal = $order->getTotalAmount();
        $originalItemCount = $order->items()->count();

        // Set test time to today (2026-01-27)
        Date::setTestNow($today->startOfSecond());

        // Execute late fee calculation
        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $today->toDateTimeString(),
                orderIds: [$order->getId()],
                addLateFee: true
            );

        $orderFresh = $order->fresh();

        // Verify: should have 0 late orders because Jan 26 is a special day
        $this->assertCount(0, $lateOrders, 'No orders should be late because grace period started on a special day');

        // Verify items haven't changed
        $this->assertEquals($originalItemCount, $orderFresh->items()->count());
        $this->assertEquals($originalTotal, $orderFresh->getTotalAmount());

        // Now test the next day (2026-01-28)
        $nextDay = $today->addDay();
        Date::setTestNow($nextDay->startOfSecond());

        // Execute late fee calculation for next day
        $lateOrders = new GenerateOrderLateFee($this->apps)
            ->execute(
                timeZonedNow: $nextDay->toDateTimeString(),
                orderIds: [$order->getId()],
                addLateFee: true
            );

        $orderFresh = $order->fresh();

        // Verify: should have 1 late order now (1 business day late: Jan 27)
        $this->assertCount(1, $lateOrders, 'Order should be late by 1 business day on Jan 28');
        $this->assertArrayHasKey('business_days_late', $orderFresh->metadata['data']);
        $this->assertEquals(1, $orderFresh->metadata['data']['business_days_late']);

        // Late fee should be added
        $this->assertEquals(2, $orderFresh->items()->count());
        $this->assertEquals($originalTotal + 100, $orderFresh->getTotalAmount());
    }

    public function testAddAndRemoveLateFee(): void
    {
        Notification::fake();
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $this->apps->set(EnumsConfigurationEnum::GRACE_PERIOD_DAYS->value, '1');

        // Create late fee product
        $lateFeeProductResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        // Create main product with late fee variant reference
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late-fee-variant-id',
                'value' => $lateFee->variants()->first()->id,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);

        // Add variants to channel and warehouse
        $this->addVariantToChannel(
            variantId: (string) $lateFee->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToChannel(
            variantId: (string) $product->variants()->first()->id,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $lateFee->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants()->first()->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = 'America/New_York';
        Date::setTestNow(now()->startOfSecond());
        $rightNow = CarbonImmutable::now($timezone);

        // Create order with original item
        $order = $this->createDraftOrder(
            variantId: $product->variants()->first()->id,
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow->subDays(32)->toDateTimeString(),
                    'late-fee-variant-id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $rightNow->subDays(31)->startOfDay()->toDateTimeString(),
                ],
            ],
        );

        $originalTotal = $order->getTotalAmount();
        $originalItemCount = $order->items()->count();

        // Verify initial state - should have 1 item
        $this->assertEquals(1, $originalItemCount);

        // Add late fee using GenerateOrderLateFee action
        new GenerateOrderLateFee($this->apps)->execute($rightNow->toDateTimeString(), [$order->getId()]);
        $orderAfterLateFee = $order->fresh();

        // Verify order has 2 items after adding late fee
        $this->assertEquals(2, $orderAfterLateFee->items()->count());

        // Now remove the late fee using the FixOrderItemCommand
        // We'll fix it back to just the original product variant
        //print_r(["tha company" => $order->companies_id]);
        Artisan::call('kanvas:movipass-fix-order-items', [
            'app_id' => $this->apps->getId(),
            '--substitute-item' => "{$lateFee->variants()->first()->id},  {$order->items->first()->variant_id}",
            '--order-ids' => "$order->id",
            '--timezone' => $timezone,
        ]);

        $orderAfterFix = $order->fresh();

        // Verify order has 1 item with correct price after removing late fee
        $this->assertEquals(2, $orderAfterFix->items()->count());
        $this->assertEquals($orderAfterFix->getTotalAmount(), $originalTotal * 2);

        // Verify the remaining item is the original product, not the late fee
        $remainingItems = $orderAfterFix->items()->get();

        foreach ($remainingItems as $item) {
            $this->assertEquals($product->variants()->first()->id, $item->variant_id);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Activities\CalculateWarehouseQuantityActivity;
use Kanvas\Connectors\Movipass\Actions\CheckExpiringOrders;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderExpirableTest extends TestCase
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
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse = $this->createChannel()->json()['data']['createChannel'];
    }

    public function createDraftOrder(
        array $metadata, 
        string $variantId, 
        int $quantity = 1,
    ): Order
    {
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

    public function testOrderExpirable(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
        ])->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );


        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $variant = Variants::find($variantResponse['id']);
        $channel = $variant->variantChannels()->where('channels_id', $channelResponse['id'])->first();
        $variantWarehouse = $channel?->productVariantWarehouse()->first();

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now('America/New_York')->subMinutes(32)->toDateTimeString(),
                    'end_at' => now('America/New_York')->subMinutes(30)->toDateTimeString(),
                ],
            ],
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
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
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
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = $response->json()['data']['createDraftOrder'];
        $order = Order::fromApp($app)->find($order['id']);
        // lets simulate the variant warehouse quantity decrease
        $activity = new CalculateWarehouseQuantityActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
        $activity->execute($order, $app, []);
        // variant quantity should decrease
        $this->assertEquals(99, $variantWarehouse->refresh()->quantity);

        // finish expired order
        Artisan::call('kanvas-souk:order-finish-expired', ['app_id' => $app->getId()]);
        $this->assertEquals(100, $variantWarehouse->refresh()->quantity);
    }

    public function testOrderExpirableLegacy(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'capacity',
                'value' => [
                    'occupiedParkingSpaces' => 50
                ]
            ]
        ])->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $variant = Variants::find($variantResponse['id']);
        $channel = $variant->variantChannels()->where('channels_id', $channelResponse['id'])->first();
        $variantWarehouse = $channel?->productVariantWarehouse()->first();

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now('America/New_York')->subMinutes(32)->toDateTimeString(),
                    'end_at' => now('America/New_York')->subMinutes(30)->toDateTimeString(),
                ],
            ],
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
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
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
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);


        $order = $response->json()['data']['createDraftOrder'];
        $order = Order::fromApp($app)->find($order['id']);
        // lets simulate the variant warehouse quantity decrease
        $activity = new CalculateWarehouseQuantityActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
        $activity->execute($order, $app, []);
        $variantProduct = $variant->product;
        // variant quantity should decrease
        $this->assertEquals(49, $variantWarehouse->refresh()->quantity);
        $this->assertEquals(49, $variantProduct->refresh()->getAttributeByName('capacity')->value['availableParkingSpaces']);

        // finish expired order
        Artisan::call('kanvas-souk:order-finish-expired', ['app_id' => $app->getId()]);
        $this->assertEquals(50, $variantWarehouse->refresh()->quantity);
        $this->assertEquals(50, $variantProduct->refresh()->getAttributeByName('capacity')->value['availableParkingSpaces']);
    }
    
    public function testOrderExpirableNotifications(): void
    {
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
        ])->json()['data']['createProduct'];


        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        
        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );


        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $timezone = "America/New_York";

        $reservation1 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => now($timezone)->toDateTimeString(),
                    'end_at' => now($timezone)->addMinutes(15)->toDateTimeString(),
                    'notify_in' => 15,
                ]
            ],
        );

        $reservation2 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => now($timezone)->toDateTimeString(),
                    'end_at' => now($timezone)->addMinutes(15)->toDateTimeString(),
                    'notify_in' => 15,
                ]
            ],
        );

        $reservation3 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => now($timezone)->toDateTimeString(),
                    'end_at' => now($timezone)->addMinutes(5)->toDateTimeString(),
                    'notify_in' => 5,
                ]
            ],
        );

        $orders = (new CheckExpiringOrders($this->apps))->execute($timezone, [15, 5], [$reservation1->getId(), $reservation2->getId(), $reservation3->getId()]);
        $this->assertEquals(3, $orders->count());

        $orders->each(function ($order) use ($timezone) {
            $orderEndTime = $order->metadata['data']['end_at'];
            $parkingTimeZone = $order->items->first(function ($item) {
                return $item->variant->first()?->attributes->first(fn ($attribute) => $attribute->key === 'timezone')?->value;
            })?->variant?->attributes?->first(fn ($attribute) => $attribute->key === 'timezone')?->value;

            $orderEndTime = Carbon::parse($orderEndTime, $parkingTimeZone ?? $timezone);
            $minutesUntilExpiry = now()->diffInMinutes($orderEndTime);
            $notifyIn = $order->metadata['data']['notify_in'];
            $this->assertEquals($notifyIn, $minutesUntilExpiry);    
        });
    }

            // public function testOrderExpirableNotifications(): void
            // {
            //     $app = app(Apps::class);
            //     $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
            //     $regionResponse = $this->createRegion()->json()['data']['createRegion'];
            //     $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
            //     $productResponse = $this->createProduct(attributes: [
            //         [
            //             'name' => 'slots',
            //             'value' => 100
            //         ]
            //     ])->json()['data']['createProduct'];
            //     $region = Regions::find($regionResponse['id']);
            //     $company = $region->company;

            //     $warehouseData = [
            //         'id' => $warehouseResponse['id'],
            //     ];

            //     $variantResponse = $this->createVariant(
            //         productId: $productResponse['id'],
            //         warehouseData: $warehouseData,
            //         attributes: [
            //             [
            //                 'name' => 'timezone',
            //                 'value' => 'America/New_York',
            //             ],
            //         ]
            //     )->json()['data']['createVariant'];

            //     $channelResponse = $this->createChannel()->json()['data']['createChannel'];

            //     $this->addVariantToChannel(
            //         variantId: $variantResponse['id'],
            //         channelId: $channelResponse['id'],
            //         warehouseData: $warehouseData
            //     );


            //     $this->addVariantToWarehouse(
            //         variantId: $variantResponse['id'],
            //         warehouseId: $warehouseResponse['id'],
            //         amount: 100
            //     );

            //     $variant = Variants::find($variantResponse['id']);
            //     $channel = $variant->variantChannels()->where('channels_id', $channelResponse['id'])->first();
            //     $variantWarehouse = $channel?->productVariantWarehouse()->first();
            //     $timezone = "America/New_York";

            //     $data = [
            //         'email' => fake()->email(),
            //         'region_id' => $region->getId(),
            //         'metadata' => [
            //             'data' => [
            //                 'start_at' => now($timezone)->toDateTimeString(),
            //                 'end_at' => now($timezone)->addMinutes(15)->toDateTimeString(),
            //                 'notify_in' => 15,
            //             ]
            //         ],
            //         'customer' => [
            //             'firstname' => fake()->firstName(),
            //             'lastname' => fake()->lastName(),
            //         ],
            //         'shipping_address' => [
            //             'address' => fake()->address(),
            //             'address_2' => fake()->postcode(),
            //             'city' => fake()->city(),
            //             'state' => fake()->state(),
            //         ],
            //         'items' => [
            //             [
            //                 'variant_id' => $variantResponse['id'],
            //                 'quantity' => 1,
            //             ],
            //         ],
            //     ];

            //     // Perform GraphQL mutation to create a draft order
            //     $response = $this->graphQL('
            //         mutation createDraftOrder($input: DraftOrderInput!) {
            //             createDraftOrder(input: $input) {
            //                 id
            //             }
            //         }
            //     ', [
            //         'input' => $data,
            //     ], [], [
            //         'X-Kanvas-Location' => $company->branch->uuid,
            //         'X-Kanvas-App' => $app->key,
            //     ]);

            //     $order = $response->json()['data']['createDraftOrder'];
            //     $order = Order::fromApp($app)->find($order['id']);
                
            
            //     $orders = (new CheckExpiringOrders($this->apps))->execute($timezone, [15, 5]);
            //     $this->assertEquals(3, $orders->count());

            //     $orders->each(function ($order) {
            //         $orderEndTime = $order->metadata['data']['end_at'];
            //         $parkingTimeZone = $order->items->first(function ($item) {
            //             return $item->variant->first()?->attributes->first(fn ($attribute) => $attribute->key === 'timezone')?->value;
            //         })?->variant?->attributes?->first(fn ($attribute) => $attribute->key === 'timezone')?->value;

            //         $orderEndTime = Carbon::parse($orderEndTime, $parkingTimeZone ?? $timezone);
            //         $minutesUntilExpiry = now()->diffInMinutes($orderEndTime, false);
            //         $notifyIn = $order->metadata['data']['notify_in'];
            //         $this->assertEquals($minutesUntilExpiry, $notifyIn);    
            //     });
            // }
}

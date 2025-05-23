<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Artisan;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Activities\CalculateWarehouseQuantityActivity;
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
}

<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\GenerateOrderLateFee;
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
                'value' => 100
            ]
        ])->json()['data']['createProduct'];

        $lateFee = Products::find($lateFeeProductResponse['id']);

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'late_fee_variant_id',
                'value' => $lateFee->variants()->first()->id
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

        $timezone = "America/New_York";
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
                    'late_fee_variant_id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $rightNow->startOfDay()->addDays(1)->toDateTimeString()
                ]
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
                    'late_fee_variant_id' => $lateFee->variants()->first()->id,
                    'late_fee_grace_start_at' => $yesterday->startOfDay()->addDays(1)->toDateTimeString()
                ]
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
}

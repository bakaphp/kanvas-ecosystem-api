<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassActivity;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class SyncMovipassActivityTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

    private function createMovipassOrder(Apps $app, array $metadataData = []): Order
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->setIntegration(
            $app,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $company,
            $user
        );

        $this->setAllowNoPaymentStatus(true, $app);

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants->first()->id,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => OrderTypeEnum::MOVIPASS->value,
            'metadata' => [
                'data' => array_merge([
                    'vehiclePlate' => 'T000001',
                    'vehicleBrand' => 'Toyota',
                    'vehicleColor' => 'red',
                    'is_manual' => false,
                ], $metadataData),
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Movipass parking test',
        ];

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $orderId = $response->json('data.createOrderFromCart.order.id');

        return Order::fromApp($app)->find($orderId);
    }

    private function createActiveDiscount(Apps $app, int $companiesId): Discount
    {
        $discountType = DiscountType::firstOrCreate(
            ['apps_id' => $app->getId(), 'name' => 'General'],
            ['apps_id' => $app->getId(), 'name' => 'General', 'is_deleted' => false]
        );

        return Discount::withoutSyncingToSearch(function () use ($app, $companiesId, $discountType): Discount {
            $discount = new Discount();
            $discount->apps_id = $app->getId();
            $discount->companies_id = $companiesId;
            $discount->name = 'Test Parking Discount';
            $discount->code = 'PARK' . strtoupper(fake()->lexify('????'));
            $discount->discount_type_id = $discountType->id;
            $discount->value = 20;
            $discount->is_percentage = true;
            $discount->min_order_value = 0;
            $discount->max_discount_amount = null;
            $discount->is_active = true;
            $discount->is_one_per_customer = false;
            $discount->usage_count = 0;
            $discount->usage_limit = null;
            $discount->is_deleted = false;
            $discount->saveOrFail();

            return $discount;
        });
    }

    public function testApplyDiscountFromMetadata(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $discount = $this->createActiveDiscount($app, $company->getId());

        $order = $this->createMovipassOrder($app, [
            'discount_id' => $discount->getId(),
        ]);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $order->orderDiscounts()->count());
        $this->assertEquals($discount->name, $order->discount_name);
        $this->assertEquals($discount->getId(), $order->voucher_id);
        $this->assertEquals($discount->code, $order->metadata['data']['discount_code']);
        $this->assertEquals($discount->name, $order->metadata['data']['discount_name']);
        $this->assertLessThan($order->total_gross_amount, $order->total_net_amount);
        $expectedDiscount = $order->total_gross_amount * 0.20;
        $this->assertEquals($expectedDiscount, $order->discount_amount);
        $this->assertEquals($order->total_gross_amount - $expectedDiscount, $order->total_net_amount);
    }

    public function testInvalidDiscountIdSkipsGracefully(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [
            'discount_id' => 99999,
        ]);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(0, $order->orderDiscounts()->count());
        $this->assertNull($order->discount_name);
        $this->assertNull($order->voucher_id);
    }

    public function testNoDiscountIdInMetadataIsNoop(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(0, $order->orderDiscounts()->count());
        $this->assertNull($order->discount_name);
        $this->assertNull($order->voucher_id);
    }
}

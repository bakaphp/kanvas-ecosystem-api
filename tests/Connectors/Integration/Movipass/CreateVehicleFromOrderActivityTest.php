<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\CreateVehicleFromOrderActivity;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class CreateVehicleFromOrderActivityTest extends TestCase
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

    public function testCreatesVehicleForManualPaidOrder(): void
    {
        $app = app(Apps::class);
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
            ['name' => 'slots', 'value' => 100],
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

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'customer' => ['email' => fake()->email()],
                'order_type' => OrderTypeEnum::MOVIPASS->value,
                'metadata' => [
                    'data' => [
                        'is_manual' => true,
                        'vehicleBrand' => 'Toyota',
                        'vehicleModel' => 'Hilux',
                        'vehicleYear' => '2022',
                        'vehiclePlate' => 'A123456',
                        'vin' => 'ABC123456789',
                        'tag_number' => '3',
                    ],
                ],
                'items' => [
                    [
                        'variant_id' => (string) $product->variants->first()->id,
                        'quantity' => 1,
                        'price' => 100,
                    ],
                ],
                'reference' => 'Manual movipass order',
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = Order::fromApp($app)->find($response->json('data.createOrderFromCart.order.id'));

        $payingUser = Users::where('id', '>', 0)->where('id', '!=', $user->getId())->firstOrFail();

        $paymentAction = new CreatePaymentAction($order, $payingUser);
        $paymentAction->runWorkflow = false;
        $paymentAction->execute([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method_type' => 'cash',
            'amount' => 100,
        ]);

        $order->refresh();

        $activity = new CreateVehicleFromOrderActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::UPDATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Vehicle created successfully', $result['message']);
        $this->assertNotNull($order->metadata['data']['variants_ids']['vehicle_variant_id']);

        $vehicleProduct = Products::fromApp($app)->find($result['product']);
        $this->assertNotNull($vehicleProduct);
        $this->assertStringContainsString('Toyota', $vehicleProduct->name);
        $this->assertStringContainsString('Hilux', $vehicleProduct->name);

        $vehicleItem = $order->allItems->firstWhere('variant_id', $result['variant']);
        $this->assertNotNull($vehicleItem);
    }

    public function testSkipsWhenPayingUserIsSameAsOrderUser(): void
    {
        $app = app(Apps::class);
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
            ['name' => 'slots', 'value' => 100],
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

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'customer' => ['email' => fake()->email()],
                'order_type' => OrderTypeEnum::MOVIPASS->value,
                'metadata' => [
                    'data' => [
                        'is_manual' => true,
                        'vehicleBrand' => 'Honda',
                        'vehicleModel' => 'Civic',
                        'vehicleYear' => '2020',
                    ],
                ],
                'items' => [
                    [
                        'variant_id' => (string) $product->variants->first()->id,
                        'quantity' => 1,
                        'price' => 100,
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = Order::fromApp($app)->find($response->json('data.createOrderFromCart.order.id'));

        $paymentAction = new CreatePaymentAction($order, $user);
        $paymentAction->runWorkflow = false;
        $paymentAction->execute([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method_type' => 'cash',
            'amount' => 100,
        ]);

        $order->refresh();

        $activity = new CreateVehicleFromOrderActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::UPDATED->value,
        ]);

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('No different paying user found', $result['message']);
    }
}

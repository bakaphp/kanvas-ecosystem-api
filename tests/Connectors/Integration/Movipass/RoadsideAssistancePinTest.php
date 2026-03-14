<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\GenerateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Actions\ValidateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassRoadsideAssistanceActivity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class RoadsideAssistancePinTest extends TestCase
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

    private function createRoadsideAssistanceOrder(): Order
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
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
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
            'order_type' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
            'metadata' => [
                'data' => [
                    'assistance_case' => [
                        'service' => 'flat_tire',
                        'provider_id' => 1,
                        'provider_name' => 'Test Provider',
                        'location' => [
                            'lat' => 18.4861,
                            'lng' => -69.9312,
                            'address' => 'Test Address, Santo Domingo',
                        ],
                        'notes' => 'Test roadside assistance case',
                    ],
                ],
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Roadside assistance case',
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

    private function executeActivity(Order $order, string $eventName, array $extraParams = []): array
    {
        $app = app(Apps::class);
        $activity = new SyncMovipassRoadsideAssistanceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        return $activity->execute($order, $app, array_merge([
            'currentEventTypeName' => $eventName,
        ], $extraParams));
    }

    /**
     * Helper: set pin_attempt in order metadata (simulates what frontend does via updateOrder).
     */
    private function setPinAttemptInMetadata(Order $order, string $pinAttempt): void
    {
        $metadata = $order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? [];
        $assistanceCase['pin_attempt'] = $pinAttempt;

        $order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $order->saveQuietly();
    }

    public function testGeneratePinOnProviderAssigned(): void
    {
        $order = $this->createRoadsideAssistanceOrder();

        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $result = $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertNotNull($order->metadata['assistance_case']['pin_hash']);
        $this->assertNotNull($order->metadata['assistance_case']['pin_generated_at']);
        $this->assertNotNull($order->metadata['assistance_case']['pin']);
        $this->assertEquals(4, strlen($order->metadata['assistance_case']['pin']));
    }

    public function testGeneratePinActionDirectly(): void
    {
        $order = $this->createRoadsideAssistanceOrder();
        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $pin = new GenerateRoadsideAssistancePinAction($order)->execute();
        $order->refresh();

        $this->assertEquals(4, strlen($pin));
        $this->assertTrue(ctype_digit($pin));
        $this->assertNotNull($order->metadata['assistance_case']['pin_hash']);
        $this->assertTrue(Hash::check($pin, $order->metadata['assistance_case']['pin_hash']));
        $this->assertNotNull($order->metadata['assistance_case']['pin_generated_at']);
    }

    public function testValidatePinActionDirectly(): void
    {
        $order = $this->createRoadsideAssistanceOrder();
        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        $pin = $order->metadata['assistance_case']['pin'];

        $result = new ValidateRoadsideAssistancePinAction(
            order: $order,
            pin: $pin,
        )->execute();

        $this->assertTrue($result);
    }

    public function testValidatePinActionFailsWithWrongPin(): void
    {
        $order = $this->createRoadsideAssistanceOrder();
        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid PIN');

        new ValidateRoadsideAssistancePinAction(
            order: $order,
            pin: '0000',
        )->execute();
    }

    public function testPinValidationViaActivityWithCorrectPin(): void
    {
        $order = $this->createRoadsideAssistanceOrder();

        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        $correctPin = $order->metadata['assistance_case']['pin'];

        // Front-end sets pin_attempt in metadata via updateOrder
        $this->setPinAttemptInMetadata($order, $correctPin);

        // UpdateOrder fires UPDATED event, activity validates PIN and transitions to DISPATCHED
        $result = $this->executeActivity($order, WorkflowEnum::UPDATED->value);
        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertStringContainsString('PIN validated', $result['message']);
        $this->assertNotNull($order->metadata['assistance_case']['pin_validated_at']);
        $this->assertArrayNotHasKey('pin_attempt', $order->metadata['assistance_case']);
        $this->assertArrayNotHasKey('pin_validation_error', $order->metadata['assistance_case']);
    }

    public function testPinValidationViaActivityWithWrongPin(): void
    {
        $order = $this->createRoadsideAssistanceOrder();

        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        // Front-end sets wrong pin_attempt
        $this->setPinAttemptInMetadata($order, '0000');

        // UpdateOrder fires UPDATED event, activity rejects PIN
        $result = $this->executeActivity($order, WorkflowEnum::UPDATED->value);
        $order->refresh();

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('PIN validation failed', $result['message']);
        $this->assertEquals(
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
            $order->orderStatus->slug
        );
        $this->assertNotNull($order->metadata['assistance_case']['pin_validation_error']);
        $this->assertEquals('Invalid PIN', $order->metadata['assistance_case']['pin_validation_error']);
        $this->assertArrayNotHasKey('pin_attempt', $order->metadata['assistance_case']);
    }

    public function testPinValidationViaActivityWithMissingPin(): void
    {
        $order = $this->createRoadsideAssistanceOrder();

        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        // Front-end does NOT set pin_attempt, just updates order metadata
        $result = $this->executeActivity($order, WorkflowEnum::UPDATED->value);
        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertStringContainsString('No pin_attempt', $result['message']);
        $this->assertEquals(
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
            $order->orderStatus->slug
        );
    }

    public function testPinInvalidatedOnServiceCompleted(): void
    {
        $order = $this->createRoadsideAssistanceOrder();

        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        $this->assertNotNull($order->metadata['assistance_case']['pin_hash']);

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::SERVICE_COMPLETED->value,
        ]);
        $order->refresh();

        $this->assertNull($order->metadata['assistance_case']['pin_hash']);
        $this->assertNotNull($order->metadata['assistance_case']['pin_invalidated_at']);
    }

    public function testPinInvalidatedOnServiceCancelled(): void
    {
        $order = $this->createRoadsideAssistanceOrder();

        $this->executeActivity($order, WorkflowEnum::CREATED->value);
        $order->refresh();

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        ]);
        $order->refresh();

        $this->assertNotNull($order->metadata['assistance_case']['pin_hash']);

        $this->executeActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => MovipassOrderStatusEnum::SERVICE_CANCELLED->value,
        ]);
        $order->refresh();

        $this->assertNull($order->metadata['assistance_case']['pin_hash']);
        $this->assertNotNull($order->metadata['assistance_case']['pin_invalidated_at']);
    }
}

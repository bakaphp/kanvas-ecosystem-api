<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Tookan;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Workflows\Activities\TookanOrderStatusActivity;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
use Kanvas\Connectors\Tookan\Handlers\TookanHandler;
use Kanvas\Connectors\Tookan\Workflows\Activities\TookanParentOrderStatusActivity;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\OrderNotification;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class TookanOrderStatusActivityTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    protected Apps $apps;
    protected Companies $company;
    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->apps = app(Apps::class);
        $user = Auth::user();
        $this->company = $user->getCurrentCompany();
        $region = Regions::getDefault($this->company, $this->apps);

        // Set up Tookan configuration
        $this->apps->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY', 'test-key'));
        $this->apps->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $this->company,
            $user
        );

        $this->setAllowNoPaymentStatus(true, $this->apps);

        // Create warehouse for company 1
        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        // Create product and variant for company 1
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        // Create second product and variant for the same company
        $product2Response = $this->createProduct()->json()['data']['createProduct'];
        $variant2Response = $this->createVariant(
            productId: $product2Response['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variant2Response['id'],
            channelId: $channelResponse['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variant2Response['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        // Create order
        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => 'customer@example.com',
            ],
            'metadata' => [
                'data' => [],
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                    'price' => 100,
                ],
                [
                    'variant_id' => $variant2Response['id'],
                    'quantity' => 1,
                    'price' => 50,
                ],
            ],
            'reference' => 'Test Order',
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
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $orderData = $response->json('data.createOrderFromCart.order');
        $this->order = Order::fromApp($this->apps)->find($orderData['id']);
    }

    /**
     * Test that user notifications are sent for user-specific statuses.
     */
    public function testUserEmailNotificationsAreSent(): void
    {
        $userStatuses = [
            OrderStatusEnum::RECEIVED,
            OrderStatusEnum::PREPARING,
            OrderStatusEnum::DISPATCHED,
            OrderStatusEnum::DELIVERED,
            OrderStatusEnum::CANCELLED,
        ];

        foreach ($userStatuses as $status) {
            Notification::fake();

            $activity = new TookanParentOrderStatusActivity(
                0,
                now()->toDateTimeString(),
                StoredWorkflow::make(),
                []
            );

            $result = $activity->execute($this->order, $this->apps, [
                'to_status' => $status->value,
            ]);

            // Assert successful execution
            $this->assertEquals('success', $result['status']);
            $this->assertEquals('Order status transition handled successfully', $result['message']);

            // Assert notification was sent
            Notification::assertSent(OrderNotification::class, 1);

            // Verify notification was sent to customer email
            Notification::assertSentTo(
                [$this->order->people],
                OrderNotification::class,
                function ($notification, $channels) use ($status) {
                    $expectedTemplate = 'user-' . strtolower($status->value);
                    return $notification->getTemplateName() === $expectedTemplate;
                }
            );
        }
    }
}

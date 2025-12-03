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
use Kanvas\Inventory\Products\Models\Products;
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
    protected Companies $company2;
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

        // Find existing products
        $product = Products::fromApp($this->apps)
            ->where('companies_id', $this->company->getId())
            ->with('variants')
            ->first();

        // Create second company for provider emails
        $this->company2 = Companies::factory()->create([
            'apps_id' => $this->apps->getId(),
            'name' => 'Provider Company',
        ]);

        $this->company2->associateUser(
            $user,
            true,
            $this->company2->defaultBranch
        );

        // Find product for second company (or use same product for simplicity)
        $product2 = Products::fromApp($this->apps)
            ->where('companies_id', $this->company2->getId())
            ->with('variants')
            ->first();

        // If no product exists for company2, use the first company's product
        if (! $product2) {
            $product2 = $product;
        }

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
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
                [
                    'variant_id' => (string) $product2->variants->first()->id,
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

            $activity = new TookanOrderStatusActivity(
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
            Notification::assertSentTimes(OrderNotification::class, 1);

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

    /**
     * Test that provider notifications are sent for provider-specific statuses.
     */
    public function testProviderEmailNotificationsAreSent(): void
    {
        $providerStatuses = [
            OrderStatusEnum::RECEIVED,
            OrderStatusEnum::READY_FOR_PICKUP,
            OrderStatusEnum::DELIVERED,
            OrderStatusEnum::CANCELLED,
        ];

        foreach ($providerStatuses as $status) {
            Notification::fake();

            $activity = new TookanOrderStatusActivity(
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

            // Assert notification was sent
            Notification::assertSentTimes(OrderNotification::class, 1);
        }
    }

    /**
     * Test that owner notifications are sent for owner-specific statuses.
     */
    public function testOwnerEmailNotificationsAreSent(): void
    {
        $ownerStatuses = [
            OrderStatusEnum::RECEIVED,
            OrderStatusEnum::READY_FOR_PICKUP,
            OrderStatusEnum::DELIVERED,
            OrderStatusEnum::CANCELLED,
        ];

        foreach ($ownerStatuses as $status) {
            Notification::fake();

            $activity = new TookanOrderStatusActivity(
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

            // Assert notification was sent
            Notification::assertSentTimes(OrderNotification::class, 1);
        }
    }

    /**
     * Test that multiple notifications are sent when status triggers multiple recipients.
     */
    public function testMultipleNotificationsForStatusThatTriggersAll(): void
    {
        Notification::fake();

        $activity = new TookanOrderStatusActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        // RECEIVED status should trigger all three notification types
        $result = $activity->execute($this->order, $this->apps, [
            'to_status' => OrderStatusEnum::RECEIVED->value,
        ]);

        // Assert successful execution
        $this->assertEquals('success', $result['status']);

        // Assert three notifications were sent (user, provider, owner)
        Notification::assertSentTimes(OrderNotification::class, 3);
    }

    /**
     * Test that no notifications are sent for statuses not in any notification array.
     */
    public function testNoNotificationsForNonTrackedStatuses(): void
    {
        Notification::fake();

        $activity = new TookanOrderStatusActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        // CHECKING_STOCK is not in any notification status array
        $result = $activity->execute($this->order, $this->apps, [
            'to_status' => OrderStatusEnum::CHECKING_STOCK->value,
        ]);

        // Assert successful execution
        $this->assertEquals('success', $result['status']);

        // Assert no notifications were sent
        Notification::assertNothingSent();
    }

    /**
     * Test activity execution without status parameter.
     */
    public function testActivityWithoutStatusParameter(): void
    {
        Notification::fake();

        $activity = new TookanOrderStatusActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($this->order, $this->apps, []);

        // Assert successful execution
        $this->assertEquals('success', $result['status']);

        // Assert no notifications were sent
        Notification::assertNothingSent();
    }

    /**
     * Test that correct template names are used for each status.
     */
    public function testCorrectTemplateNamesAreUsed(): void
    {
        $testCases = [
            [
                'status' => OrderStatusEnum::RECEIVED,
                'expectedTemplates' => [
                    'user-received',
                    'provider-received',
                    'owner-received',
                ],
            ],
            [
                'status' => OrderStatusEnum::PREPARING,
                'expectedTemplates' => [
                    'user-preparing',
                ],
            ],
            [
                'status' => OrderStatusEnum::READY_FOR_PICKUP,
                'expectedTemplates' => [
                    'provider-ready_for_pickup',
                    'owner-ready_for_pickup',
                ],
            ],
        ];

        foreach ($testCases as $testCase) {
            Notification::fake();

            $activity = new TookanOrderStatusActivity(
                0,
                now()->toDateTimeString(),
                StoredWorkflow::make(),
                []
            );

            $activity->execute($this->order, $this->apps, [
                'to_status' => $testCase['status']->value,
            ]);

            // Verify each expected template was used
            foreach ($testCase['expectedTemplates'] as $expectedTemplate) {
                Notification::assertSent(
                    OrderNotification::class,
                    function ($notification) use ($expectedTemplate) {
                        return $notification->getTemplateName() === $expectedTemplate;
                    }
                );
            }
        }
    }
}

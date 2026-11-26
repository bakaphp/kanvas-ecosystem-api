<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Activities\NotifyDeliveryOrderStatusActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\OrderNotification;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Souk\Concerns\CreatesDeliveryOrder;
use Tests\TestCase;

#[Group('serial')]
final class NotifyDeliveryOrderStatusActivityTest extends TestCase
{
    use CreatesDeliveryOrder;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'commerce', 'crm', 'ecosystem'];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        $this->setUpDeliveryOrderStatuses();
        Notification::fake();
    }

    #[DataProvider('statusNotices')]
    public function testEachStatusEmailsTheCustomerAndTheOwnerItConcerns(string $toStatus, array $expected): void
    {
        $order = $this->orderWithCustomerEmail($toStatus);

        $this->notify($order, $toStatus);

        $this->assertEqualsCanonicalizing($expected, array_map(
            fn (OrderNotification $notification): string => $notification->getTemplateName(),
            $this->sentNotifications()
        ));
    }

    public static function statusNotices(): array
    {
        return [
            'placed' => ['verifying', ['user-order-verifying', 'owner-order-verifying']],
            'confirmed' => ['confirmed', ['user-order-confirmed']],
            'preparing' => ['ready-for-dispatch', ['user-order-ready-for-dispatch']],
            'shipped' => ['dispatched', ['user-order-dispatched']],
            'delivered' => ['delivered', ['user-order-delivered', 'owner-order-delivered']],
            'returned' => ['returned', ['user-order-returned', 'owner-order-returned']],
            'out of stock' => ['out-of-stock', ['user-order-out-of-stock']],
            'cancelled' => ['cancelled', ['user-order-cancelled', 'owner-order-cancelled']],
        ];
    }

    public function testTheShippedEmailCarriesTheTrackingNumberAndLink(): void
    {
        $order = $this->orderWithCustomerEmail('dispatched', [
            'tracking_client_id' => 'AWB-123',
        ]);
        $order->set(NotifyDeliveryOrderStatusActivity::TRACKING_URL_FIELD, 'https:\\carrier.test\track\AWB-123');

        $this->notify($order, 'dispatched');

        $data = $this->sentNotifications()[0]->getData();
        $this->assertSame('AWB-123', $data['tracking_number']);
        $this->assertSame('https:\\carrier.test\track\AWB-123', $data['tracking_url']);
    }

    public function testTheShippedEmailIsStillSentWithoutTrackingData(): void
    {
        $order = $this->orderWithCustomerEmail('dispatched');

        $this->notify($order, 'dispatched');

        $data = $this->sentNotifications()[0]->getData();
        $this->assertNull($data['tracking_number']);
        $this->assertNull($data['tracking_url']);
    }

    public function testThePreparingNoticeIsSkippedOnceTheOrderMovedOn(): void
    {
        $moved = $this->orderWithCustomerEmail('dispatched');

        $skipped = $this->notify($moved, 'ready-for-dispatch');

        $this->assertSame('skipped', $skipped['status']);
        Notification::assertNothingSent();

        $waiting = $this->orderWithCustomerEmail('ready-for-dispatch');

        $sent = $this->notify($waiting, 'ready-for-dispatch');

        $this->assertSame('success', $sent['status']);
    }

    public function testAMovedOnOrderStillGetsTheNoticeOfEveryOtherStatus(): void
    {
        $order = $this->orderWithCustomerEmail('dispatched');

        $result = $this->notify($order, 'confirmed');

        $this->assertSame('success', $result['status']);
    }

    public function testAnUnnotifiedStatusSendsNothing(): void
    {
        $order = $this->orderWithCustomerEmail('confirmed');

        $result = $this->notify($order, 'some-other-status');

        $this->assertSame('skipped', $result['status']);
        Notification::assertNothingSent();
    }

    public function testTheCompanyDoesNotNeedAnyCarrierIntegration(): void
    {
        $order = $this->orderWithCustomerEmail('delivered');

        $result = $this->notify($order, 'delivered');

        $this->assertSame('success', $result['status']);
        $this->assertNotEmpty($this->sentNotifications());
    }

    private function orderWithCustomerEmail(string $status, array $overrides = []): Order
    {
        $order = $this->deliveryOrder($this->company, $status, $overrides);
        $order->people->addEmail('maria@customer.test');

        return $order;
    }

    private function notify(Order $order, string $toStatus): array
    {
        return new NotifyDeliveryOrderStatusActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $order,
            app(Apps::class),
            ['currentEventTypeName' => WorkflowEnum::STATUS_TRANSITION->value, 'to_status' => $toStatus]
        );
    }

    private function sentNotifications(): array
    {
        $sent = [];

        Notification::assertSentOnDemand(OrderNotification::class, function (OrderNotification $notification) use (&$sent): bool {
            $sent[] = $notification;

            return true;
        });

        return $sent;
    }
}

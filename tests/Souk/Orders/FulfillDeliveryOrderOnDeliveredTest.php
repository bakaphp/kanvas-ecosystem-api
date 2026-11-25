<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\SetupDeliveryOrderTypeAction;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\Souk\Concerns\CreatesDeliveryOrder;
use Tests\TestCase;

final class FulfillDeliveryOrderOnDeliveredTest extends TestCase
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

    public function testMovingAnOrderToDeliveredByHandFulfillsIt(): void
    {
        $order = $this->deliveryOrder($this->company, 'dispatched', ['fulfillment_status' => 'pending']);

        $result = $this->moveTo($order, 'delivered');

        $this->assertSame('success', $result['status']);
        $this->assertSame('delivered', $this->statusSlugOf($order));
        $this->assertTrue($order->isFulfilled());
    }

    public function testAStatusOutsideTheConfigDoesNotFulfill(): void
    {
        $order = $this->deliveryOrder($this->company, 'dispatched', ['fulfillment_status' => 'pending']);

        $this->moveTo($order, 'returned');

        $this->assertSame('returned', $this->statusSlugOf($order));
        $this->assertFalse($order->isFulfilled());
    }

    public function testAnOrderTypeWithoutTheConfigDoesNotFulfill(): void
    {
        $order = $this->deliveryOrder($this->company, 'dispatched', ['fulfillment_status' => 'pending']);
        OrderTypes::find($order->order_types_id)->update(['config' => []]);

        $this->moveTo($order->refresh(), 'delivered');

        $this->assertSame('delivered', $this->statusSlugOf($order));
        $this->assertFalse($order->isFulfilled());
    }

    public function testSetupWritesTheFulfillConfigAndKeepsTheRestOfTheConfig(): void
    {
        $type = OrderTypes::find($this->deliveryOrderStatuses['verifying']->order_types_id);
        $type->update(['config' => ['card_velocity' => ['per_day' => 3], 'fulfill_on_status' => ['other']]]);

        new SetupDeliveryOrderTypeAction(app(Apps::class))->execute();

        $config = $type->refresh()->config;
        $this->assertSame(['other', 'delivered'], $config['fulfill_on_status']);
        $this->assertSame(['confirmed'], $config['capture_on_status']);
        $this->assertSame(['per_day' => 3], $config['card_velocity']);
        $this->assertTrue($type->fulfillsOnStatus('delivered'));
        $this->assertFalse($type->fulfillsOnStatus('dispatched'));
    }

    private function moveTo(Order $order, string $slug): array
    {
        $status = OrderStatus::find($this->deliveryOrderStatuses[$slug]->getId());

        return new TransitionOrderStateAction($order, auth()->user(), $status)->execute(true);
    }
}

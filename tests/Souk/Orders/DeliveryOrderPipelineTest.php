<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\AlertUnverifiedDeliveryOrdersAction;
use Kanvas\Souk\Orders\Actions\SetupDeliveryOrderTypeAction;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Tests\TestCase;

final class DeliveryOrderPipelineTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'ecosystem'];

    private Apps $apps;
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $result = new SetupDeliveryOrderTypeAction($this->apps)->execute();

        foreach ($result['statuses'] as $status) {
            $this->statuses[$status->slug] = $status;
        }
    }

    public function testVerifyingIsTheDefaultStatusAndEveryStepOfThePipelineIsReachable(): void
    {
        $this->assertTrue((bool) $this->statuses['verifying']->is_default);

        $path = ['verifying', 'confirmed', 'ready-for-dispatch', 'dispatched', 'delivered'];

        foreach (array_slice($path, 0, -1) as $index => $from) {
            $this->assertTrue($this->transitionExists($from, $path[$index + 1]), "{$from} to {$path[$index + 1]}");
        }

        $this->assertTrue($this->transitionExists('confirmed', 'out-of-stock'));
        $this->assertTrue($this->transitionExists('dispatched', 'returned'));
        $this->assertTrue($this->transitionExists('dispatched', 'cancelled'));
        $this->assertFalse($this->transitionExists('verifying', 'dispatched'));
    }

    public function testStatusesCarryTheCustomerFacingNamesAndARerunRenamesExistingOnes(): void
    {
        $expected = [
            'verifying' => 'Order placed, pending confirmation',
            'confirmed' => 'Order confirmed',
            'ready-for-dispatch' => 'Preparing to ship',
            'dispatched' => 'Shipped',
            'delivered' => 'Delivered',
            'returned' => 'Returned',
            'out-of-stock' => 'Out of stock',
            'cancelled' => 'Cancelled',
        ];

        $this->statuses['confirmed']->update(['name' => 'Confirmed']);

        $statuses = collect(new SetupDeliveryOrderTypeAction($this->apps)->execute()['statuses'])
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->slug => $status->refresh()->name])
            ->all();

        $this->assertSame($expected, $statuses);
    }

    public function testRunningTheSetupTwiceKeepsTheSameGraph(): void
    {
        $before = OrderStatusTransitions::query()->where('order_types_id', $this->statuses['confirmed']->order_types_id)->count();

        new SetupDeliveryOrderTypeAction($this->apps)->execute();

        $this->assertSame(
            $before,
            OrderStatusTransitions::query()->where('order_types_id', $this->statuses['confirmed']->order_types_id)->count()
        );
    }

    public function testMarkingTheOrderPaidNoLongerMovesItOutOfVerifying(): void
    {
        $order = $this->orderIn('verifying');

        $order->markAsPaid(auth()->user());

        $this->assertSame(DeliveryOrderStatusEnum::VERIFYING->value, $order->refresh()->orderStatus->slug);
        $this->assertSame('paid', $order->payment_status);
    }

    public function testAnOrderPastTheLimitIsAlertedOnce(): void
    {
        Notification::fake();
        $overdue = $this->orderIn('verifying', Carbon::now()->subHours(25));
        $recent = $this->orderIn('verifying', Carbon::now()->subHours(2));
        $paid = $this->orderIn('confirmed', Carbon::now()->subHours(30));

        $first = new AlertUnverifiedDeliveryOrdersAction($this->apps)->execute();
        $second = new AlertUnverifiedDeliveryOrdersAction($this->apps)->execute();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertNotNull($overdue->get(AlertUnverifiedDeliveryOrdersAction::ALERTED_AT_FIELD));
        $this->assertNull($recent->get(AlertUnverifiedDeliveryOrdersAction::ALERTED_AT_FIELD));
        $this->assertNull($paid->get(AlertUnverifiedDeliveryOrdersAction::ALERTED_AT_FIELD));
    }

    private function transitionExists(string $from, string $to): bool
    {
        return OrderStatusTransitions::query()
            ->where('from_status_id', $this->statuses[$from]->getId())
            ->where('to_status_id', $this->statuses[$to]->getId())
            ->exists();
    }

    private function orderIn(string $slug, ?Carbon $createdAt = null): Order
    {
        $status = OrderStatus::find($this->statuses[$slug]->getId());

        $order = Order::factory()
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->withUserId(auth()->user()->getId())
            ->create([
                'order_types_id' => $status->order_types_id,
                'order_status_id' => $status->getId(),
            ]);

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $order->refresh();
    }
}

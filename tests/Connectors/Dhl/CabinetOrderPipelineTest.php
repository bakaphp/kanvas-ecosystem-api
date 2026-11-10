<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Dhl\Actions\AlertUnverifiedCabinetOrdersAction;
use Kanvas\Connectors\Dhl\Actions\SetupCabinetOrderAction;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Tests\TestCase;

final class CabinetOrderPipelineTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'ecosystem'];

    private Apps $apps;
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $result = new SetupCabinetOrderAction($this->apps)->execute();

        foreach ($result['statuses'] as $status) {
            $this->statuses[$status->slug] = $status;
        }
    }

    public function testVerifyingIsTheDefaultStatusAndEveryStepOfThePipelineIsReachable(): void
    {
        $this->assertTrue((bool) $this->statuses['verifying']->is_default);

        $path = ['verifying', 'paid', 'ready-for-dispatch', 'dispatched', 'delivered'];

        foreach (array_slice($path, 0, -1) as $index => $from) {
            $this->assertTrue($this->transitionExists($from, $path[$index + 1]), "{$from} to {$path[$index + 1]}");
        }

        $this->assertTrue($this->transitionExists('paid', 'out-of-stock'));
        $this->assertTrue($this->transitionExists('dispatched', 'returned'));
        $this->assertTrue($this->transitionExists('dispatched', 'cancelled'));
        $this->assertFalse($this->transitionExists('verifying', 'dispatched'));
    }

    public function testRunningTheSetupTwiceKeepsTheSameGraph(): void
    {
        $before = OrderStatusTransitions::query()->where('order_types_id', $this->statuses['paid']->order_types_id)->count();

        new SetupCabinetOrderAction($this->apps)->execute();

        $this->assertSame(
            $before,
            OrderStatusTransitions::query()->where('order_types_id', $this->statuses['paid']->order_types_id)->count()
        );
    }

    public function testCapturingThePaymentMovesAVerifyingOrderToPaid(): void
    {
        $order = $this->orderIn('verifying');

        $order->markAsPaid(auth()->user());

        $this->assertSame(OrderStatusEnum::PAID->value, $order->refresh()->orderStatus->slug);
    }

    public function testAnOrderPastTheLimitIsAlertedOnce(): void
    {
        Notification::fake();
        $overdue = $this->orderIn('verifying', Carbon::now()->subHours(25));
        $recent = $this->orderIn('verifying', Carbon::now()->subHours(2));
        $paid = $this->orderIn('paid', Carbon::now()->subHours(30));

        $first = new AlertUnverifiedCabinetOrdersAction($this->apps)->execute();
        $second = new AlertUnverifiedCabinetOrdersAction($this->apps)->execute();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertNotNull($overdue->get(CustomFieldEnum::UNVERIFIED_ALERTED_AT->value));
        $this->assertNull($recent->get(CustomFieldEnum::UNVERIFIED_ALERTED_AT->value));
        $this->assertNull($paid->get(CustomFieldEnum::UNVERIFIED_ALERTED_AT->value));
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

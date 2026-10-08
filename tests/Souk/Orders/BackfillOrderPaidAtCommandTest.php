<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Tests\TestCase;

final class BackfillOrderPaidAtCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    private function paidOrderWithoutPaidAt(string $createdAt): Order
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['payment_status' => 'paid']);

        Order::query()->whereKey($order->getId())->update([
            'paid_at' => null,
            'created_at' => $createdAt,
        ]);

        return $order;
    }

    private function addPaidTransition(Order $order, string $changedAt): void
    {
        $type = OrderTypes::firstOrCreate([
            'apps_id' => $order->apps_id,
            'companies_id' => $order->companies_id,
            'name' => 'paid-at-backfill',
        ]);

        $paid = OrderStatus::firstOrCreate([
            'order_types_id' => $type->getId(),
            'apps_id' => $order->apps_id,
            'slug' => 'paid',
            'name' => 'Paid',
            'is_default' => false,
            'is_final' => true,
        ]);

        OrderTransitionHistory::create([
            'apps_id' => $order->apps_id,
            'companies_id' => $order->companies_id,
            'order_id' => $order->getId(),
            'from_status_id' => null,
            'to_status_id' => $paid->getId(),
            'changed_at' => $changedAt,
            'is_current' => false,
            'is_deleted' => 0,
        ]);
    }

    private function addPaidPayment(Order $order, string $paymentDate): void
    {
        $payment = new Payments();
        $payment->apps_id = $order->apps_id;
        $payment->companies_id = $order->companies_id;
        $payment->users_id = $order->users_id;
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = $paymentDate;
        $payment->payment_method = 'card';
        $payment->amount = 10.0;
        $payment->currency = 'USD';
        $payment->status = 'paid';
        $payment->is_deleted = false;
        $payment->saveOrFail();
    }

    public function testFillsPaidAtFromTransitionThenPaymentThenCreatedAt(): void
    {
        $fromTransition = $this->paidOrderWithoutPaidAt('2026-06-01 10:00:00');
        $this->addPaidTransition($fromTransition, '2026-06-03 08:00:00');
        $this->addPaidPayment($fromTransition, '2026-06-05 08:00:00');

        $fromPayment = $this->paidOrderWithoutPaidAt('2026-07-01 10:00:00');
        $this->addPaidPayment($fromPayment, '2026-07-04');

        $fromCreatedAt = $this->paidOrderWithoutPaidAt('2026-08-01 10:00:00');

        $this->artisan('kanvas-souk:backfill-order-paid-at', ['app_id' => app(Apps::class)->getId(), '--chunk' => 2])
            ->assertSuccessful();

        $this->assertSame('2026-06-03 08:00:00', $fromTransition->fresh()->paid_at->toDateTimeString());
        $this->assertSame('2026-07-04 00:00:00', $fromPayment->fresh()->paid_at->toDateTimeString());
        $this->assertSame('2026-08-01 10:00:00', $fromCreatedAt->fresh()->paid_at->toDateTimeString());
    }

    public function testDryRunLeavesPaidAtEmpty(): void
    {
        $order = $this->paidOrderWithoutPaidAt('2026-08-01 10:00:00');

        $this->artisan('kanvas-souk:backfill-order-paid-at', ['app_id' => app(Apps::class)->getId(), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($order->fresh()->paid_at);
    }

    public function testDoesNotOverwriteAnExistingPaidAt(): void
    {
        $order = $this->paidOrderWithoutPaidAt('2026-08-01 10:00:00');
        Order::query()->whereKey($order->getId())->update(['paid_at' => '2026-08-02 09:00:00']);

        $this->artisan('kanvas-souk:backfill-order-paid-at', ['app_id' => app(Apps::class)->getId()])
            ->assertSuccessful();

        $this->assertSame('2026-08-02 09:00:00', $order->fresh()->paid_at->toDateTimeString());
    }
}

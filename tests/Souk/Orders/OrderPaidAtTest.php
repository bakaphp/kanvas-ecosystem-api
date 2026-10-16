<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Tests\TestCase;

final class OrderPaidAtTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    private function createOrder(?string $paymentStatus): Order
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['payment_status' => $paymentStatus]);
    }

    public function testOrderCreatedUnpaidHasNoPaidAt(): void
    {
        $order = $this->createOrder('unpaid');

        $this->assertNull($order->fresh()->paid_at);
    }

    public function testPayingAnOrderStampsPaidAt(): void
    {
        Carbon::setTestNow('2026-10-05 14:30:00');
        $order = $this->createOrder('unpaid');

        $order->payment_status = PaymentStatusEnum::PAID->value;
        $order->saveQuietly();

        $this->assertSame('2026-10-05 14:30:00', $order->fresh()->paid_at->toDateTimeString());
        Carbon::setTestNow();
    }

    public function testQuietUpdateToPaidStampsPaidAt(): void
    {
        $order = $this->createOrder('unpaid');

        $order->updateQuietly(['payment_status' => PaymentStatusEnum::PAID->value]);

        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function testMarkingAPaidOrderPaidAgainKeepsTheOriginalPaidAt(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $order = $this->createOrder(PaymentStatusEnum::PAID->value);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $order->fresh()->updateQuietly(['payment_status' => PaymentStatusEnum::PAID->value]);

        $this->assertSame('2026-10-01 09:00:00', $order->fresh()->paid_at->toDateTimeString());
        Carbon::setTestNow();
    }

    public function testRefundingAPaidOrderClearsPaidAt(): void
    {
        $order = $this->createOrder(PaymentStatusEnum::PAID->value);

        $order->updateQuietly(['payment_status' => PaymentStatusEnum::REVERSED->value]);

        $this->assertNull($order->fresh()->paid_at);
    }
}

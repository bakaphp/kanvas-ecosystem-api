<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Events\PaymentStatusChanged;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Observers\PaymentObserver;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class PaymentAuthorizedWorkflowTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PaymentStatusChanged::class]);
    }

    public function testAPaymentBecomingAuthorizedFiresTheEventOnItsOrder(): void
    {
        $order = $this->orderExpecting(1);
        $payment = $this->paymentFor($order, PaymentStatusEnum::AUTHORIZED);

        new PaymentObserver()->updated($payment);
    }

    public function testAPaymentBecomingPaidDoesNotFireIt(): void
    {
        $order = $this->orderExpecting(0);
        $payment = $this->paymentFor($order, PaymentStatusEnum::PAID);

        new PaymentObserver()->updated($payment);
    }

    public function testAnUnchangedStatusDoesNotFireIt(): void
    {
        $order = $this->orderExpecting(0);
        $payment = $this->paymentFor($order, PaymentStatusEnum::AUTHORIZED);
        $payment->syncOriginal();

        new PaymentObserver()->updated($payment);
    }

    public function testAnEnumObjectAssignedAsStatusDoesNotFireItBecauseTheLegacyPathDependsOnThat(): void
    {
        $order = $this->orderExpecting(0);
        $payment = $this->paymentFor($order, PaymentStatusEnum::PAID);
        $payment->status = PaymentStatusEnum::AUTHORIZED;

        new PaymentObserver()->updated($payment);
    }

    private function orderExpecting(int $times): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 1;
        $order->uuid = 'order-uuid';
        $order->setRelation('app', app(Apps::class));
        $order->setRelation('company', null);
        $order->shouldReceive('fireWorkflow')
            ->with(WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value, true, Mockery::type('array'))
            ->times($times);

        return $order;
    }

    private function paymentFor(Order $order, PaymentStatusEnum $status): Payments
    {
        $payment = new Payments();
        $payment->id = 1;
        $payment->status = PaymentStatusEnum::PROCESSING->value;
        $payment->syncOriginal();
        $payment->status = $status->value;
        $payment->setRelation('payable', $order);

        return $payment;
    }
}

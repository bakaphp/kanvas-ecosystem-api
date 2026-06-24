<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Mockery;
use Tests\TestCase;

final class OrderMarkAsPaidWorkflowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Every card processor (Stripe, Azul, CardNet, PayWay) funnels through
     * Payments::markAsPaid -> Order::markAsPaid -> firePaidSideEffects. That path must emit
     * AFTER_PAYMENT_INTENT so payment-triggered workflows (e.g. TeeTime booking activation)
     * fire on card payments, not just on the wallet path.
     */
    public function testMarkAsPaidFiresAfterPaymentIntentWorkflow(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = static::$cachedUser ?? auth()->user();
        $company = $user->getCurrentCompany();

        $order = Mockery::mock(Order::class)->makePartial();
        $order->shouldAllowMockingProtectedMethods();
        $order->exists = true;
        $order->setRelation('app', $app);
        $order->setRelation('company', $company);

        $order->shouldReceive('transitionToStatus')->andReturnNull();
        $order->shouldReceive('completed')->andReturnNull();

        $order->shouldReceive('fireWorkflow')
            ->with(WorkflowEnum::UPDATED->value, true, Mockery::type('array'))
            ->once();

        $order->shouldReceive('fireWorkflow')
            ->with(WorkflowEnum::AFTER_PAYMENT_INTENT->value, true, Mockery::type('array'))
            ->once();

        $order->markAsPaid($user);

        $this->assertSame('paid', $order->payment_status);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\UpdateOrderAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Tests\TestCase;

final class UpdateOrderActionPaidGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = auth()->user();

        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create($overrides);
    }

    public function testUpdateFailsWithValidationExceptionWhenOrderIsPaid(): void
    {
        $user = auth()->user();
        $order = $this->makeOrder(['payment_status' => PaymentStatusEnum::PAID->value]);

        $this->expectException(ValidationException::class);

        new UpdateOrderAction(
            $order,
            ['fulfillment_status' => 'processing'],
            $user,
        )->execute();
    }

    public function testUpdateProceedsNormallyWhenOrderIsUnpaid(): void
    {
        $user = auth()->user();
        $order = $this->makeOrder();

        $result = new UpdateOrderAction(
            $order,
            ['fulfillment_status' => 'pending'],
            $user,
        )->disableWorkflow()->execute();

        $this->assertInstanceOf(Order::class, $result);
    }

    public function testValidationMessageIsCorrect(): void
    {
        $user = auth()->user();
        $order = $this->makeOrder(['payment_status' => PaymentStatusEnum::PAID->value]);

        try {
            new UpdateOrderAction(
                $order,
                ['fulfillment_status' => 'completed'],
                $user,
            )->execute();
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
            $this->assertSame('paid_order_cannot_be_updated', $e->errors()['order'][0]);
        }
    }
}

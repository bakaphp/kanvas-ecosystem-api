<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Carbon;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Payments\Actions\CancelStalePaymentsAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class CancelStalePaymentsTest extends OrderBase
{
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variantResponse['id'];

        $this->apps->set(ConfigurationEnum::CANCEL_STALE_PAYMENTS->value, '1');
    }

    public function testCancelStalePayments(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $payment = $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Test payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'apps_id' => $this->apps->getId(),
            'status' => PaymentStatusEnum::PENDING->value,
            'payment_method' => 'card',
        ]);

        $payment->update(['updated_at' => Carbon::now()->subMinutes(35)]);

        $action = new CancelStalePaymentsAction($this->apps);
        $cancelled = $action->execute();

        $this->assertTrue($cancelled->contains('id', $payment->id));

        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->status);
    }

    public function testDoesNotCancelFreshPayments(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $payment = $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Test payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'apps_id' => $this->apps->getId(),
            'status' => PaymentStatusEnum::PENDING->value,
            'payment_method' => 'card',
        ]);

        $action = new CancelStalePaymentsAction($this->apps);
        $cancelled = $action->execute();

        $this->assertFalse($cancelled->contains('id', $payment->id));

        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::PENDING->value, $payment->status);
    }

    public function testCancelStalePaymentsWithCustomTtl(): void
    {
        $this->apps->set(ConfigurationEnum::STALE_PAYMENT_TTL_MINUTES->value, '10');

        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $payment = $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Test payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'apps_id' => $this->apps->getId(),
            'status' => PaymentStatusEnum::WAITING_DEVICE_DATA->value,
            'payment_method' => 'card',
        ]);

        $payment->update(['updated_at' => Carbon::now()->subMinutes(15)]);

        $action = new CancelStalePaymentsAction($this->apps);
        $cancelled = $action->execute();

        $this->assertTrue($cancelled->contains('id', $payment->id));

        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->status);
    }

    public function testDoesNotCancelPaidOrAuthorizedPayments(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $paidPayment = $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Paid payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'apps_id' => $this->apps->getId(),
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method' => 'card',
        ]);

        $paidPayment->update(['updated_at' => Carbon::now()->subMinutes(60)]);

        $order2 = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $authorizedPayment = $order2->payments()->create([
            'amount' => $order2->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Authorized payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order2->companies_id,
            'apps_id' => $this->apps->getId(),
            'status' => PaymentStatusEnum::AUTHORIZED->value,
            'payment_method' => 'card',
        ]);

        $authorizedPayment->update(['updated_at' => Carbon::now()->subMinutes(60)]);

        $action = new CancelStalePaymentsAction($this->apps);
        $cancelled = $action->execute();

        $this->assertFalse($cancelled->contains('id', $paidPayment->id));
        $this->assertFalse($cancelled->contains('id', $authorizedPayment->id));

        $paidPayment->refresh();
        $authorizedPayment->refresh();
        $this->assertEquals(PaymentStatusEnum::PAID->value, $paidPayment->status);
        $this->assertEquals(PaymentStatusEnum::AUTHORIZED->value, $authorizedPayment->status);
    }

    public function testCancelStalePaymentLeavesOrderUntouched(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->update(['payment_status' => PaymentStatusEnum::PROCESSING->value]);

        $payment = $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Test payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'apps_id' => $this->apps->getId(),
            'status' => PaymentStatusEnum::PROCESSING->value,
            'payment_method' => 'card',
        ]);

        $payment->update(['updated_at' => Carbon::now()->subMinutes(35)]);

        $action = new CancelStalePaymentsAction($this->apps);
        $action->execute();

        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->status);

        $order->refresh();
        $this->assertEquals(PaymentStatusEnum::PROCESSING->value, $order->payment_status);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay;

use DomainException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Connectors\PayWay\Enums\CustomFieldEnum;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\PayWay\PayWayProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Tests\Connectors\PayWay\Fakes\FakePayWayClient;
use Tests\TestCase;

class PayWayProcessorOpsTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;
    private FakePayWayClient $fakeClient;
    private PayWayProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();

        $this->currentCompany->set(ConfigurationEnum::PAYWAY_ENCRYPTION_KEY->value, 'test-secret-key-32bytes-padded!!');

        $this->fakeClient = new FakePayWayClient();
        $this->processor = new PayWayProcessor(
            app: $this->currentApp,
            company: $this->currentCompany,
            client: $this->fakeClient,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testVoidHappyPathTransitionsToCancelled(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, 'compra-12345');
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('voidTransaction', PayWayResponse::fromArray([
            'returnCode' => '00',
            'message' => 'Anulación exitosa',
            'success' => true,
        ]));

        $result = $this->processor->void($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('Void successful', $result->message);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
        $this->assertCount(1, $this->fakeClient->getCalls('voidTransaction'));
    }

    public function testVoidAfterCutoffSurfacesPayWayMessage(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, 'compra-99999');
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('voidTransaction', PayWayResponse::fromArray([
            'returnCode' => '02',
            'message' => 'Anulación rechazada - fuera de ventana',
            'success' => false,
        ]));

        $result = $this->processor->void($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame('Anulación rechazada - fuera de ventana', $result->message);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $this->fakeClient->getCalls('voidTransaction'));
    }

    public function testVoidWithMissingNumeroCompraFails(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, null);
        $order = $this->buildOrderMock();

        $result = $this->processor->void($payment, $order);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No PayWay transaction reference', $result->message);
        $this->assertCount(0, $this->fakeClient->getCalls('voidTransaction'));
    }

    public function testRefundThrowsDomainException(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, 'compra-11111');
        $order = $this->buildOrderMock();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PayWay does not support refunds — use voidPayment within the same business day.');

        $this->processor->refund($payment, $order);
    }

    public function testCaptureOnPaidIsNoopSuccess(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, 'compra-22222');
        $payment->payment_intent_id = 'intent-abc';
        $order = $this->buildOrderMock();

        $result = $this->processor->capture($payment, $order);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Already captured', $result->message);
        $this->assertCount(0, $this->fakeClient->getCalls('payUsing3ds'));
        $this->assertCount(0, $this->fakeClient->getCalls('voidTransaction'));
        $this->assertCount(0, $this->fakeClient->getCalls('getTransaction'));
    }

    public function testCaptureOnNonPaidThrowsDomainException(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value, null);
        $order = $this->buildOrderMock();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PayWay does not support separate capture');

        $this->processor->capture($payment, $order);
    }

    public function testVerifyMapsCurrentState(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, 'compra-33333');
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('getTransaction', PayWayResponse::fromArray([
            'returnCode' => '00',
            'message' => 'TRANSACCION EXITOSA',
            'success' => true,
            'numeroPayWay' => 'payway-txn-987',
        ]));

        $result = $this->processor->verify($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('TRANSACCION EXITOSA', $result->message);
        $this->assertSame('00', $result->responseCode);
        $this->assertSame('payway-txn-987', $result->transactionId);
        $this->assertSame('', $result->isoCode);

        // verify() is read-only — status must not change.
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $this->fakeClient->getCalls('getTransaction'));
    }

    private function buildPaymentMock(
        string $initialStatus,
        ?string $numeroCompra,
    ): Payments {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->exists = true;
        $payment->id = 1;
        $payment->idempotency_key = 'aaaabbbb-cccc-4000-8000-ddddeeeeffff';
        $payment->status = $initialStatus;
        $payment->payment_intent_id = null;
        $payment->metadata = $numeroCompra !== null
            ? ['data' => ['payway_numero_compra' => $numeroCompra]]
            : ['data' => []];

        $paymentMethod = $this->buildPaymentMethodMock();
        $payment->shouldReceive('paymentMethod')->andReturn($paymentMethod)->byDefault();
        $payment->shouldReceive('getAttribute')->with('paymentMethod')->andReturn($paymentMethod)->byDefault();
        $payment->shouldReceive('getRelationValue')->with('paymentMethod')->andReturn($paymentMethod)->byDefault();

        $payment->shouldReceive('save')->andReturnSelf()->byDefault();
        $payment->shouldReceive('saveQuietly')->andReturnSelf()->byDefault();

        $payment->shouldReceive('addMetadata')
            ->andReturnUsing(function (array $data) use ($payment) {
                $existing = $payment->metadata ?? [];
                $payment->metadata = [
                    ...$existing,
                    ...$data,
                    'data' => [
                        ...($existing['data'] ?? []),
                        ...($data['data'] ?? []),
                    ],
                ];
            })
            ->byDefault();

        $payment->shouldReceive('getMetadata')
            ->andReturnUsing(function (string $key) use ($payment) {
                return $payment->metadata['data'][$key] ?? null;
            })
            ->byDefault();

        $payment->shouldReceive('addLog')->andReturnNull()->byDefault();

        $payment->shouldReceive('markAsPaid')
            ->andReturnUsing(function (array $metadata = []) use ($payment) {
                $payment->status = PaymentStatusEnum::PAID->value;
            })
            ->byDefault();

        return $payment;
    }

    private function buildPaymentMethodMock(): PaymentMethods
    {
        $pm = Mockery::mock(PaymentMethods::class)->makePartial();
        $pm->exists = true;
        $pm->id = 1;
        $pm->metadata = [];

        $pm->shouldReceive('getMetadata')
            ->andReturnUsing(function (string $key) use ($pm) {
                return $pm->metadata[$key] ?? null;
            })
            ->byDefault();

        $pm->shouldReceive('save')->andReturnSelf()->byDefault();

        return $pm;
    }

    private function buildOrderMock(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->order_number = 99999;
        $order->ip_address = '127.0.0.1';
        $order->user_email = 'ops@example.com';
        $order->currency = 'USD';
        $order->total_gross_amount = 50.00;
        $order->total_net_amount = 50.00;

        $order->shouldReceive('getTotalAmount')->andReturn(50.00)->byDefault();

        return $order;
    }
}

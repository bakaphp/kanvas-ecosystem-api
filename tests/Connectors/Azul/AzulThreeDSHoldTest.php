<?php

declare(strict_types=1);

namespace Tests\Connectors\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentResponse;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Services\AzulService;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Models\PaymentMethods;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class AzulThreeDSHoldTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testFinalizeLeavesAHeldPaymentAuthorizedSoItCanBeCapturedOrVoided(): void
    {
        $payment = $this->challengePayment(['three_ds_use_hold' => true]);
        $payment->shouldNotReceive('markAsPaid');

        $result = $this->processor()->finalizeChallenge($payment, $this->order());

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $result->status);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testFinalizeMarksAnUnheldPaymentPaid(): void
    {
        $payment = $this->challengePayment([]);
        $payment->shouldReceive('markAsPaid')->once();

        $result = $this->processor()->finalizeChallenge($payment, $this->order());

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $result->status);
    }

    public function testChallengeHoldPrefersTheCallContextThenThePaymentFlagThenTheAppDefault(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::AZUL_USE_HOLD->value, '1');

        $payment = new Payments();
        $this->assertTrue($this->resolveChallengeHold($payment, []));
        $this->assertFalse($this->resolveChallengeHold($payment, ['use_hold' => false]));

        $payment->metadata = ['use_hold' => false];
        $this->assertFalse($this->resolveChallengeHold($payment, []));
        $this->assertTrue($this->resolveChallengeHold($payment, ['use_hold' => true]));

        $app->set(ConfigurationEnum::AZUL_USE_HOLD->value, '0');
        $payment->metadata = ['use_hold' => true];
        $this->assertTrue($this->resolveChallengeHold($payment, []));
    }

    public function testAuthorizeWithHoldLeavesThePaymentAuthorized(): void
    {
        $payment = $this->authorizablePayment();
        $payment->shouldNotReceive('markAsPaid');
        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('hold')->once()->andReturn($this->approvedResponse());
        $service->shouldNotReceive('sale');

        $result = $this->processorWith($service)->authorize($payment, $this->dopOrder(), ['use_hold' => true]);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED, $result->paymentStatus);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testAuthorizeWithoutHoldMarksThePaymentPaid(): void
    {
        $payment = $this->authorizablePayment();
        $payment->shouldReceive('markAsPaid')->once();
        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('sale')->once()->andReturn($this->approvedResponse());
        $service->shouldNotReceive('hold');

        $result = $this->processorWith($service)->authorize($payment, $this->dopOrder(), ['use_hold' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID, $result->paymentStatus);
    }

    private function resolveChallengeHold(Payments $payment, array $context): bool
    {
        $method = new ReflectionMethod(AzulProcessor::class, 'resolveChallengeHold');

        return $method->invoke($this->processor(), $payment, $context);
    }

    private function processor(): AzulProcessor
    {
        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('processThreeDSMethod')->andReturn(new AzulPaymentResponse(
            azulOrderId: 'AZ-1',
            isoCode: '00',
            responseMessage: 'APROBADA',
            ticket: 'T-1',
        ));

        return new AzulProcessor(app(Apps::class), Companies::first(), $service);
    }

    private function order(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 1;

        return $order;
    }

    private function processorWith(AzulService $service): AzulProcessor
    {
        return new AzulProcessor(app(Apps::class), Companies::first(), $service);
    }

    private function approvedResponse(): AzulPaymentResponse
    {
        return new AzulPaymentResponse(
            azulOrderId: 'AZ-1',
            isoCode: '00',
            responseMessage: 'APROBADA',
            ticket: 'T-1',
        );
    }

    private function dopOrder(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 1;
        $order->currency = 'DOP';
        $order->order_number = 'ORD-1';
        $order->shouldReceive('set')->andReturn(new AppsCustomFields());
        $order->shouldReceive('getTotalDueAmount')->andReturn(100.0);

        return $order;
    }

    private function authorizablePayment(): Payments
    {
        $paymentMethod = Mockery::mock(PaymentMethods::class)->makePartial();
        $paymentMethod->shouldReceive('getMetadata')->andReturn('vault-token');
        $paymentMethod->shouldReceive('save')->andReturn(true);

        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->status = PaymentStatusEnum::PROCESSING->value;
        $payment->setRelation('paymentMethod', $paymentMethod);
        $payment->shouldReceive('save')->andReturn(true);
        $payment->shouldReceive('addLog')->andReturn(null);

        return $payment;
    }

    private function challengePayment(array $data): Payments
    {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->payment_intent_id = 'AZ-1';
        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $payment->metadata = ['data' => [...$data, '3ds_pa_res' => 'cres']];
        $payment->shouldReceive('save')->andReturn(true);
        $payment->shouldReceive('addLog')->andReturn(null);

        return $payment;
    }
}

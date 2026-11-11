<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentResponse;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Connectors\Azul\Services\AzulService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Mockery;
use Tests\TestCase;

class AzulProcessorRefundTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    protected Apps $kanvasApp;
    protected Companies $company;
    protected Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = static::$cachedUser;
        $this->company = $this->kanvasUser->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPartialRefundCreatesRefundAndKeepsPaymentPaid(): void
    {
        $payment = $this->persistPayment(100.00);

        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('refund')
            ->once()
            ->with(Mockery::on(fn (AzulPaymentRequest $request) => (int) $request->amount === 4000))
            ->andReturn(new AzulPaymentResponse(azulOrderId: 'AZ-REF-1', responseMessage: 'APROBADA', ticket: '77'));

        $result = $this->processor($service)->refund($payment, $this->buildOrderMock(), 40.00, ['user' => $this->kanvasUser]);

        $this->assertTrue($result->success);

        $refund = PaymentRefund::where('payments_id', $payment->getId())->sole();
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->status);
        $this->assertSame('AZ-REF-1', $refund->processor_refund_id);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertEquals(60.00, $payment->getRefundableAmount());
    }

    public function testFullRefundReversesPayment(): void
    {
        $payment = $this->persistPayment(100.00);

        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('refund')->once()->andReturn(new AzulPaymentResponse(azulOrderId: 'AZ-REF-2'));

        $this->assertTrue($this->processor($service)->refund($payment, $this->buildOrderMock())->success);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testFailedRefundIsMarkedFailedAndPaymentStaysPaid(): void
    {
        $payment = $this->persistPayment(100.00);

        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('refund')->once()->andThrow(new AzulException('Declined'));

        $this->assertFalse($this->processor($service)->refund($payment, $this->buildOrderMock(), 10.00)->success);

        $this->assertSame(
            RefundStatusEnum::FAILED->value,
            PaymentRefund::where('payments_id', $payment->getId())->sole()->status
        );
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->refresh()->status);
    }

    private function processor(AzulService $service): AzulProcessor
    {
        return new AzulProcessor($this->kanvasApp, $this->company, $service);
    }

    private function buildOrderMock(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->currency = 'DOP';

        $order->shouldReceive('getId')->andReturn(1)->byDefault();
        $order->shouldReceive('getTotalDueAmount')->andReturn(100.00)->byDefault();
        $order->shouldReceive('getTotalTaxAmount')->andReturn(0.0)->byDefault();

        return $order;
    }

    private function persistPayment(float $amount): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = $this->kanvasUser->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = 0;
        $payment->payable_type = Order::class;
        $payment->concept = 'Azul test payment';
        $payment->payment_date = date('Y-m-d');
        $payment->amount = $amount;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->processor = 'azul';
        $payment->payment_intent_id = 'AZ-ORIG-1';
        $payment->metadata = ['data' => []];
        $payment->saveOrFail();

        return $payment;
    }
}

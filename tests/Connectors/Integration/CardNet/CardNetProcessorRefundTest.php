<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CardNet;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CardNet\DataTransferObject\CardNetPurchaseRequest;
use Kanvas\Connectors\CardNet\DataTransferObject\CardNetPurchaseResponse;
use Kanvas\Connectors\CardNet\Exceptions\CardNetException;
use Kanvas\Connectors\CardNet\Services\CardNetService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundReasonEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNet\CardNetProcessor;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CardNetProcessorRefundTest extends TestCase
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

    public function testFullRefundCreatesCompletedRefundAndReversesPayment(): void
    {
        $payment = $this->persistPayment(100.00);
        $service = $this->serviceExpectingRefunds([10000]);

        $result = $this->processor($service)->refund($payment, $this->buildOrderMock(), null, [
            'user' => $this->kanvasUser,
            'reason' => RefundReasonEnum::OUT_OF_STOCK->value,
            'note' => 'No stock left',
        ]);

        $this->assertTrue($result->success);

        $refund = PaymentRefund::where('payments_id', $payment->getId())->sole();
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->status);
        $this->assertEquals(100.00, (float) $refund->amount);
        $this->assertSame('out_of_stock', $refund->reason);
        $this->assertSame('No stock left', $refund->note);
        $this->assertSame($this->kanvasUser->getId(), (int) $refund->users_id);
        $this->assertSame(98765, $refund->metadata['cardnet_purchase_id']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testRefundLogsRecordTheActingUserNotThePaymentOwner(): void
    {
        $payment = $this->persistPayment(100.00);
        $actor = Users::query()->where('id', '>', 0)->where('id', '!=', $payment->users_id)->firstOrFail();

        $this->processor($this->serviceExpectingRefunds([1000]))
            ->refund($payment, $this->buildOrderMock(), 10.00, ['user' => $actor]);

        $logUsers = PaymentLogs::query()
            ->where('payments_id', $payment->getId())
            ->whereIn('event_type', ['refund_initiated', 'refund_completed', 'refund_success'])
            ->pluck('users_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->assertSame([$actor->getId()], $logUsers);
    }

    public function testRefundReplaysThePurchaseIpAndUserAgent(): void
    {
        $payment = $this->persistPayment(100.00);
        $payment->metadata = ['data' => ['customer_ip' => '203.0.113.7', 'customer_user_agent' => 'ShopperApp/2.1']];
        $payment->saveOrFail();

        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('refund')
            ->once()
            ->with(12345, Mockery::on(
                fn (CardNetPurchaseRequest $request) => $request->customerIp === '203.0.113.7'
                    && $request->customerUserAgent === 'ShopperApp/2.1'
            ))
            ->andReturn($this->approvedResponse());

        $this->assertTrue($this->processor($service)->refund($payment, $this->buildOrderMock(), 10.00)->success);
    }

    public function testPartialRefundsKeepPaymentPaidUntilFullyRefunded(): void
    {
        $payment = $this->persistPayment(100.00);
        $service = $this->serviceExpectingRefunds([4000, 6000]);
        $processor = $this->processor($service);

        $this->assertTrue($processor->refund($payment, $this->buildOrderMock(), 40.00)->success);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertEquals(40.00, $payment->getRefundedAmount());
        $this->assertEquals(60.00, $payment->getRefundableAmount());

        $this->assertTrue($processor->refund($payment, $this->buildOrderMock(), 60.00)->success);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
        $this->assertEquals(0.0, $payment->getRefundableAmount());
    }

    public function testRefundAboveRefundableAmountIsRejectedWithoutCallingCardNet(): void
    {
        $payment = $this->persistPayment(100.00);
        $service = $this->serviceExpectingRefunds([]);

        $result = $this->processor($service)->refund($payment, $this->buildOrderMock(), 150.00);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exceeds refundable amount', $result->message);
        $this->assertSame(0, PaymentRefund::where('payments_id', $payment->getId())->count());
    }

    public function testSameIdempotencyKeyRefundsOnce(): void
    {
        $payment = $this->persistPayment(100.00);
        $service = $this->serviceExpectingRefunds([3000]);
        $processor = $this->processor($service);
        $context = ['idempotency_key' => 'admin-refund-1'];

        $first = $processor->refund($payment, $this->buildOrderMock(), 30.00, $context);
        $second = $processor->refund($payment->refresh(), $this->buildOrderMock(), 30.00, $context);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame('Refund already completed', $second->message);
        $this->assertSame(1, PaymentRefund::where('payments_id', $payment->getId())->count());
    }

    public function testIdempotencyKeyReusedWithDifferentAmountIsRejected(): void
    {
        $payment = $this->persistPayment(100.00);
        $service = $this->serviceExpectingRefunds([3000]);
        $processor = $this->processor($service);
        $context = ['idempotency_key' => 'admin-refund-2'];

        $processor->refund($payment, $this->buildOrderMock(), 30.00, $context);
        $result = $processor->refund($payment->refresh(), $this->buildOrderMock(), 50.00, $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('different amount', $result->message);
    }

    public function testFailedRefundIsMarkedFailedAndCanBeRetriedWithSameKey(): void
    {
        $payment = $this->persistPayment(100.00);
        $context = ['idempotency_key' => 'admin-refund-3'];

        $failing = Mockery::mock(CardNetService::class);
        $failing->shouldReceive('refund')->once()->andThrow(new CardNetException('Refund declined'));

        $result = $this->processor($failing)->refund($payment, $this->buildOrderMock(), 25.00, $context);

        $this->assertFalse($result->success);
        $refund = PaymentRefund::where('payments_id', $payment->getId())->sole();
        $this->assertSame(RefundStatusEnum::FAILED->value, $refund->status);
        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);

        $retry = $this->processor($this->serviceExpectingRefunds([2500]))
            ->refund($payment, $this->buildOrderMock(), 25.00, $context);

        $this->assertTrue($retry->success);
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->refresh()->status);
        $this->assertSame(1, PaymentRefund::where('payments_id', $payment->getId())->count());
    }

    public function testPartialCaptureLimitsTheRefundableAmount(): void
    {
        $payment = $this->persistPayment(100.00, PaymentStatusEnum::AUTHORIZED);

        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('commit')->once()->with(12345, 8000)->andReturn($this->approvedResponse());
        $service->shouldReceive('refund')->once()
            ->with(12345, Mockery::on(fn (CardNetPurchaseRequest $request) => $request->amount === 8000))
            ->andReturn($this->approvedResponse());

        $processor = $this->processor($service);

        $this->assertTrue($processor->capture($payment, $this->buildOrderMock(), 80.00)->success);

        $payment->refresh();
        $this->assertEquals(80.00, $payment->getCapturedAmount());
        $this->assertEquals(80.00, $payment->getRefundableAmount());

        $this->assertFalse($processor->refund($payment, $this->buildOrderMock(), 100.00)->success);
        $this->assertTrue($processor->refund($payment, $this->buildOrderMock())->success);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    private function processor(CardNetService $service): CardNetProcessor
    {
        return new CardNetProcessor($this->kanvasApp, $service);
    }

    /**
     * @param int[] $amounts
     */
    private function serviceExpectingRefunds(array $amounts): CardNetService&MockInterface
    {
        $service = Mockery::mock(CardNetService::class);

        if ($amounts === []) {
            $service->shouldNotReceive('refund');

            return $service;
        }

        foreach ($amounts as $amount) {
            $service->shouldReceive('refund')
                ->once()
                ->with(12345, Mockery::on(fn (CardNetPurchaseRequest $request) => $request->amount === $amount))
                ->andReturn($this->approvedResponse());
        }

        return $service;
    }

    private function approvedResponse(): CardNetPurchaseResponse
    {
        return CardNetPurchaseResponse::fromResponse([
            'Response' => [
                'PurchaseId' => 98765,
                'Transaction' => [
                    'TransactionID' => 555,
                    'ApprovalCode' => 'A1B2C3',
                    'Status' => 'Approved',
                    'Steps' => [
                        ['ResponseCode' => '00', 'ResponseMessage' => 'Aprobada'],
                    ],
                ],
            ],
        ]);
    }

    private function buildOrderMock(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->currency = 'DOP';
        $order->order_number = 'ORD-1';

        $order->shouldReceive('getId')->andReturn(1)->byDefault();
        $order->shouldReceive('getTotalDueAmount')->andReturn(100.00)->byDefault();

        return $order;
    }

    private function persistPayment(float $amount, PaymentStatusEnum $status = PaymentStatusEnum::PAID): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = $this->kanvasUser->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = 0;
        $payment->payable_type = Order::class;
        $payment->concept = 'CardNet test payment';
        $payment->payment_date = date('Y-m-d');
        $payment->amount = $amount;
        $payment->currency = 'DOP';
        $payment->status = $status->value;
        $payment->processor = 'cardnet';
        $payment->payment_intent_id = '12345';
        $payment->metadata = ['data' => []];
        $payment->saveOrFail();

        return $payment;
    }
}

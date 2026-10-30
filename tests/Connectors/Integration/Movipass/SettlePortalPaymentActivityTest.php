<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Exception;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Handlers\EchoPayHandler;
use Kanvas\Connectors\Movipass\Actions\SettleAuthorizedPaymentAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\SettlePortalPaymentActivity;
use Kanvas\Connectors\PasoRapido\DataTransferObject\InvoiceDetails;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Jobs\RetryPaymentCaptureJob;
use Kanvas\Souk\Payments\Jobs\RetryPaymentReversalJob;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Mockery\MockInterface;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Souk\PortalMockFlowCase;

final class SettlePortalPaymentActivityTest extends PortalMockFlowCase
{
    use HasIntegrationCompany;

    public function testAuthorizedPasoRapidoPaymentIsCapturedAndTheOrderPaid(): void
    {
        $captured = 0;
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('capturePayment')->once()->andReturnUsing(function () use (&$captured): array {
            $captured++;

            return ['id' => 'capture-1', 'status' => 'CAPTURED'];
        });
        $service->shouldNotReceive('reversePayment');
        [$payment, $order] = $this->authorizedPasoRapidoPayment($service);

        $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame(1, $captured);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
    }

    public function testFailedPasoRapidoConfirmationVoidsTheAuthorizationWithoutCapturing(): void
    {
        $reversed = null;
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldNotReceive('capturePayment');
        $service->shouldReceive('reversePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$reversed): bool {
            $reversed = $input->transactionId;

            return true;
        })->andReturn(['id' => 'reversal-1', 'status' => 'REVERSED']);
        [$payment, $order] = $this->authorizedPasoRapidoPayment($service);
        $rejecting = Mockery::mock(PasoRapidoService::class);
        $rejecting->shouldReceive('confirmPayment')->once()->andThrow(new Exception('Tag rejected'));

        $result = new SettleAuthorizedPaymentAction($payment, $rejecting)->execute();

        $this->assertSame(PaymentStatusEnum::FAILED->value, $result['status']);
        $payment = $payment->fresh();
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
        $this->assertSame($payment->payment_intent_id, $reversed);
        $this->assertNotSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
    }

    public function testFailedVoidLeavesThePaymentFailedAndPendingForTheReversalRetry(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldNotReceive('capturePayment');
        $service->shouldReceive('reversePayment')->once()->andThrow(new Exception('gateway down'));
        [$payment, $order] = $this->authorizedPasoRapidoPayment($service);
        $rejecting = Mockery::mock(PasoRapidoService::class);
        $rejecting->shouldReceive('confirmPayment')->once()->andThrow(new Exception('Tag rejected'));
        Queue::fake();

        new SettleAuthorizedPaymentAction($payment, $rejecting)->execute();

        $payment = $payment->fresh();
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        Queue::assertPushed(RetryPaymentReversalJob::class);
    }

    public function testTransientCaptureFailureAfterConfirmationKeepsTheHoldAndSchedulesACaptureRetry(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('capturePayment')->once()->andThrow(new Exception('gateway down'));
        $service->shouldNotReceive('reversePayment');
        [$payment, $order] = $this->authorizedPasoRapidoPayment($service);
        Bus::fake([RetryPaymentCaptureJob::class]);

        try {
            $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

            $payment = $payment->fresh();
            $this->assertSame(RetryPaymentCaptureJob::PENDING_KEY, $result['status']);
            $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
            $this->assertTrue($payment->metadata[RetryPaymentCaptureJob::PENDING_KEY]);
            Bus::assertDispatched(RetryPaymentCaptureJob::class);
        } finally {
            Cache::lock(UniqueLock::getKey(new RetryPaymentCaptureJob($this->apps, $payment)))->forceRelease();
        }
    }

    public function testHardCaptureDeclineAfterConfirmationFlagsForReviewWithoutVoidOrRetry(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('capturePayment')->once()->andThrow(new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => ['errorInformation' => ['reason' => 'PROCESSOR_DECLINED']]]
        ));
        $service->shouldNotReceive('reversePayment');
        [$payment] = $this->authorizedPasoRapidoPayment($service);
        Bus::fake([RetryPaymentCaptureJob::class]);

        $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

        $payment = $payment->fresh();
        $this->assertSame(RetryPaymentCaptureJob::NEEDS_REVIEW_KEY, $result['status']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertTrue($payment->metadata[RetryPaymentCaptureJob::NEEDS_REVIEW_KEY]);
        $this->assertTrue($payment->paymentLogs()->where('event_type', 'payment_capture_needs_review')->exists());
        Bus::assertNotDispatched(RetryPaymentCaptureJob::class);
    }

    public function testAuthorizedMovipassPaymentIsCapturedWithoutPasoRapidoConfirmation(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('capturePayment')->once()->passthru();
        $service->shouldNotReceive('reversePayment');
        $paymentId = $this->createAuthorizedPortalPayment($service);
        $payment = Payments::find($paymentId);
        $order = Order::find($payment->payable_id);
        $order->setOrderType(OrderTypeEnum::MOVIPASS->value);
        $pasoRapido = Mockery::mock(PasoRapidoService::class);
        $pasoRapido->shouldNotReceive('confirmPayment');

        $result = new SettleAuthorizedPaymentAction($payment->fresh(), $pasoRapido)->execute();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
    }

    public function testActivitySkipsPaymentsOfOrdersThatAreNotMovipassTypes(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldNotReceive('capturePayment');
        $service->shouldNotReceive('reversePayment');
        $paymentId = $this->createAuthorizedPortalPayment($service);
        $payment = Payments::find($paymentId);
        $order = $payment->payable;
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::ECHO_PAY,
            EchoPayHandler::class,
            $this->company,
            Auth::user()
        );

        $result = new SettlePortalPaymentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($order, $this->apps, ['payment_id' => $payment->getId()]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->fresh()->status);
    }

    private function authorizedPasoRapidoPayment(MockInterface $service): array
    {
        $paymentId = $this->createAuthorizedPortalPayment($service);
        $payment = Payments::find($paymentId);
        $order = Order::find($payment->payable_id);
        $order->setOrderType(OrderTypeEnum::PASO_RAPIDO->value);
        $order->metadata = ['data' => ['paso_rapido_tag' => '317169']];
        $order->saveQuietly();

        return [$payment->fresh(), $order->fresh()];
    }

    private function confirmingPasoRapido(): PasoRapidoService
    {
        $service = Mockery::mock(PasoRapidoService::class);
        $service->shouldReceive('confirmPayment')->once()->andReturn(new PaymentConfirmResponse(
            message: 'Payment confirmed',
            amount: 100.0,
            order: 1,
            tag: '317169',
            account: 1,
            creditDate: now()->toDateString(),
            invoiceDetails: new InvoiceDetails(
                commercialName: 'Test',
                document: '001',
                fiscalCredit: false,
                invoice: 'B0100000001',
                pdf: 'https://test.local/invoice.pdf',
                reference: 'ref-1',
            ),
        ));

        return $service;
    }
}

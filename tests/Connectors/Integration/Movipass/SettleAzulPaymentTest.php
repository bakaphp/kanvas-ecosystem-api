<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Exception;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentResponse;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Connectors\Azul\Handlers\AzulHandler;
use Kanvas\Connectors\Azul\Services\AzulService;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\Movipass\Actions\SettleAuthorizedPaymentAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\SettleAuthorizedMovipassPaymentActivity;
use Kanvas\Connectors\PasoRapido\DataTransferObject\InvoiceDetails;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum as PasoRapidoCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Events\PasoRapidoRechargeProcessed;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Jobs\RetryPaymentCaptureJob;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;
use Tests\Traits\FailsBroadcasts;

final class SettleAzulPaymentTest extends TestCase
{
    use DatabaseTransactions;
    use FailsBroadcasts;
    use HasIntegrationCompany;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private Apps $currentApp;
    private Companies $company;
    private MockInterface $azul;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->company = Auth::user()->getCurrentCompany();
        $this->azul = Mockery::mock(AzulService::class);
        app()->bind(
            'payment_processor.azul',
            fn ($app, array $params) => new AzulProcessor($params['app'], $params['company'], $this->azul)
        );
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testConfirmedRechargeCapturesTheHoldAndPaysTheOrder(): void
    {
        $this->azul->shouldReceive('capture')->once()->andReturn($this->approved('AZ-POST'));
        $this->azul->shouldNotReceive('void');
        [$payment, $order] = $this->heldPasoRapidoPayment();

        $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido($payment->payment_intent_id))->execute();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
    }

    public function testRejectedRechargeVoidsTheHoldWithoutCapturing(): void
    {
        $this->azul->shouldNotReceive('capture');
        $this->azul->shouldReceive('void')->once()->andReturn($this->approved('AZ-VOID'));
        [$payment, $order] = $this->heldPasoRapidoPayment();

        $result = new SettleAuthorizedPaymentAction($payment, $this->rejectingPasoRapido())->execute();

        $this->assertSame(PaymentStatusEnum::FAILED->value, $result['status']);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->fresh()->status);
        $this->assertNotSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
    }

    public function testConfirmedRechargeIsBroadcastOnTheOrderChannelBeforeTheCapture(): void
    {
        Event::fake([PasoRapidoRechargeProcessed::class]);
        $this->azul->shouldReceive('capture')->once()->andReturnUsing(function (): never {
            Event::assertDispatched(PasoRapidoRechargeProcessed::class);

            throw new AzulException('Connection failed: timeout');
        });
        [$payment, $order] = $this->heldPasoRapidoPayment();
        Bus::fake([RetryPaymentCaptureJob::class]);

        try {
            new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

            Event::assertDispatched(
                PasoRapidoRechargeProcessed::class,
                fn (PasoRapidoRechargeProcessed $event): bool => $event->broadcastOn()->name === 'order-' . $order->uuid
                    && $event->broadcastAs() === 'paso_rapido.recharge'
                    && $event->broadcastWith() === [
                        'order_id' => $order->getId(),
                        'status' => PasoRapidoRechargeProcessed::SUCCESS,
                        'message' => 'Payment confirmed',
                    ]
            );
        } finally {
            Cache::lock(UniqueLock::getKey(new RetryPaymentCaptureJob($this->currentApp, $payment)))->forceRelease();
        }
    }

    public function testRejectedRechargeIsBroadcastAsFailed(): void
    {
        Event::fake([PasoRapidoRechargeProcessed::class]);
        $this->azul->shouldReceive('void')->once()->andReturn($this->approved('AZ-VOID'));
        [$payment, $order] = $this->heldPasoRapidoPayment();

        new SettleAuthorizedPaymentAction($payment, $this->rejectingPasoRapido())->execute();

        Event::assertDispatched(
            PasoRapidoRechargeProcessed::class,
            fn (PasoRapidoRechargeProcessed $event): bool => $event->orderId === $order->getId()
                && $event->status === PasoRapidoRechargeProcessed::FAILED
                && $event->message === 'Tag rejected'
        );
    }

    public function testAnOrderThatIsNotPasoRapidoBroadcastsNoRecharge(): void
    {
        Event::fake([PasoRapidoRechargeProcessed::class]);
        $this->azul->shouldReceive('capture')->once()->andReturn($this->approved('AZ-POST'));
        [$payment, $order] = $this->heldPasoRapidoPayment();
        $order->setOrderType(OrderTypeEnum::MOVIPASS->value);
        $order->saveQuietly();

        new SettleAuthorizedPaymentAction(Payments::find($payment->getId()))->execute();

        Event::assertNotDispatched(PasoRapidoRechargeProcessed::class);
    }

    public function testABrokenRechargeBroadcastIsReportedAndNeverBlocksTheCapture(): void
    {
        Exceptions::fake();
        $this->failBroadcastsNamed('paso_rapido.recharge');
        $this->azul->shouldReceive('capture')->once()->andReturn($this->approved('AZ-POST'));
        [$payment, $order] = $this->heldPasoRapidoPayment();

        $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
        $this->assertBroadcastFailureReported();
    }

    public function testUnreachableAzulOnCaptureKeepsTheHoldAndSchedulesARetry(): void
    {
        $this->azul->shouldReceive('capture')->once()->andThrow(new AzulException('Connection failed: timeout'));
        $this->azul->shouldNotReceive('void');
        [$payment] = $this->heldPasoRapidoPayment();
        Bus::fake([RetryPaymentCaptureJob::class]);

        try {
            $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

            $payment = $payment->fresh();
            $this->assertSame(RetryPaymentCaptureJob::PENDING_KEY, $result['status']);
            $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
            Bus::assertDispatched(RetryPaymentCaptureJob::class);
        } finally {
            Cache::lock(UniqueLock::getKey(new RetryPaymentCaptureJob($this->currentApp, $payment)))->forceRelease();
        }
    }

    public function testDeclinedCaptureFlagsForReviewWithoutVoidOrRetry(): void
    {
        $this->azul->shouldReceive('capture')->once()->andThrow(new AzulException(
            'Post declined',
            0,
            null,
            ['IsoCode' => '99']
        ));
        $this->azul->shouldNotReceive('void');
        [$payment] = $this->heldPasoRapidoPayment();
        Bus::fake([RetryPaymentCaptureJob::class]);

        $result = new SettleAuthorizedPaymentAction($payment, $this->confirmingPasoRapido())->execute();

        $payment = $payment->fresh();
        $this->assertSame(RetryPaymentCaptureJob::NEEDS_REVIEW_KEY, $result['status']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        Bus::assertNotDispatched(RetryPaymentCaptureJob::class);
    }

    public function testAStaleSecondCaptureLosesTheClaimAndNeverReachesAzul(): void
    {
        $this->azul->shouldReceive('capture')->once()->andReturn($this->approved('AZ-POST'));
        [$payment, $order] = $this->heldPasoRapidoPayment();
        $stale = Payments::find($payment->getId());

        $first = new AzulProcessor($this->currentApp, $this->company, $this->azul)->capture($payment, $order);
        $second = new AzulProcessor($this->currentApp, $this->company, $this->azul)->capture($stale, $order);

        $this->assertTrue($first->success);
        $this->assertTrue($second->skipped);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
    }

    public function testAStaleVoidOfACapturedHoldNeverReachesAzulNorOverwritesPaid(): void
    {
        $this->azul->shouldNotReceive('void');
        [$payment, $order] = $this->heldPasoRapidoPayment();
        $stale = Payments::find($payment->getId());
        $payment->markAsPaid();

        $result = new AzulProcessor($this->currentApp, $this->company, $this->azul)->void($stale, $order);

        $this->assertTrue($result->skipped);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
    }

    public function testAFailedVoidReleasesTheClaimBackToAuthorized(): void
    {
        $this->azul->shouldReceive('void')->once()->andThrow(new AzulException('Void declined'));
        [$payment, $order] = $this->heldPasoRapidoPayment();

        $result = new AzulProcessor($this->currentApp, $this->company, $this->azul)->void($payment, $order);

        $this->assertFalse($result->success);
        $this->assertFalse($result->skipped);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->fresh()->status);
    }

    public function testAnUnexpectedCaptureFailureReleasesTheClaimBeforeRethrowing(): void
    {
        $this->azul->shouldReceive('capture')->once()->andThrow(new RuntimeException('malformed gateway body'));
        [$payment, $order] = $this->heldPasoRapidoPayment();

        try {
            new AzulProcessor($this->currentApp, $this->company, $this->azul)->capture($payment, $order);
            $this->fail('The capture swallowed an unexpected exception.');
        } catch (RuntimeException) {
            $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->fresh()->status);
        }
    }

    public function testAnUnexpectedVoidFailureReleasesTheClaimBeforeRethrowing(): void
    {
        $this->azul->shouldReceive('void')->once()->andThrow(new RuntimeException('malformed gateway body'));
        [$payment, $order] = $this->heldPasoRapidoPayment();

        try {
            new AzulProcessor($this->currentApp, $this->company, $this->azul)->void($payment, $order);
            $this->fail('The void swallowed an unexpected exception.');
        } catch (RuntimeException) {
            $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->fresh()->status);
        }
    }

    public function testActivitySettlesAnAzulHoldThroughTheAzulIntegration(): void
    {
        $this->azul->shouldReceive('capture')->once()->andReturn($this->approved('AZ-POST'));
        [$payment, $order] = $this->heldPasoRapidoPayment();
        $order->set(PasoRapidoCustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::PAID->value);
        $order->set(EchoPayCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
        $this->connectAzul();

        $result = $this->activity()->execute($order, $this->currentApp, ['payment_id' => $payment->getId()]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->fresh()->status);
    }

    public function testActivityIgnoresAnAzulSaleThatIsAlreadyPaid(): void
    {
        $this->azul->shouldNotReceive('capture');
        $this->azul->shouldNotReceive('void');
        [$payment, $order] = $this->heldPasoRapidoPayment();
        $payment->update(['status' => PaymentStatusEnum::PAID->value]);
        $this->connectAzul();

        $result = $this->activity()->execute($order, $this->currentApp, ['payment_id' => $payment->getId()]);

        $this->assertSame('skipped', $result['status']);
    }

    public function testOnlyPortalAndAzulPaymentsAreSettled(): void
    {
        [$payment] = $this->heldPasoRapidoPayment();
        $this->assertTrue(SettleAuthorizedPaymentAction::settles($payment));

        $payment->paymentMethod->update(['processor' => 'cardnet']);
        $this->assertFalse(SettleAuthorizedPaymentAction::settles($payment->fresh()));
    }

    private function heldPasoRapidoPayment(): array
    {
        $user = Auth::user();
        $people = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($user->getId())
            ->create();
        $order = Order::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create(['currency' => 'DOP']);
        $order->setOrderType(OrderTypeEnum::PASO_RAPIDO->value);
        $order->metadata = ['data' => ['paso_rapido_tag' => '317169']];
        $order->saveQuietly();

        $paymentMethod = new PaymentMethods();
        $paymentMethod->apps_id = $this->currentApp->getId();
        $paymentMethod->companies_id = $this->company->getId();
        $paymentMethod->users_id = $user->getId();
        $paymentMethod->payment_methods_brand = 'visa';
        $paymentMethod->payment_ending_numbers = '0000';
        $paymentMethod->expiration_date = '12/30';
        $paymentMethod->processor = 'azul';
        $paymentMethod->metadata = [];
        $paymentMethod->saveOrFail();

        $payment = $order->payments()->create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $user->getId(),
            'payment_methods_id' => $paymentMethod->getId(),
            'amount' => $order->getTotalAmount(),
            'payment_date' => now()->toDateString(),
            'concept' => 'Paso Rapido recharge',
            'currency' => 'DOP',
            'status' => PaymentStatusEnum::AUTHORIZED->value,
            'payment_method' => 'card',
            'processor' => 'azul',
            'payment_intent_id' => 'AZ-HOLD-' . uniqid(),
        ]);

        return [$payment->fresh(), $order->fresh()];
    }

    private function approved(string $azulOrderId): AzulPaymentResponse
    {
        return new AzulPaymentResponse(
            azulOrderId: $azulOrderId,
            isoCode: '00',
            responseMessage: 'APROBADA',
            ticket: 'T-1',
        );
    }

    private function activity(): SettleAuthorizedMovipassPaymentActivity
    {
        return new SettleAuthorizedMovipassPaymentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }

    private function connectAzul(): void
    {
        $this->setIntegration(
            $this->currentApp,
            IntegrationsEnum::AZUL,
            AzulHandler::class,
            $this->company,
            Auth::user()
        );
    }

    private function rejectingPasoRapido(): PasoRapidoService
    {
        $service = Mockery::mock(PasoRapidoService::class);
        $service->shouldReceive('confirmPayment')->once()->andThrow(new Exception('Tag rejected'));

        return $service;
    }

    private function confirmingPasoRapido(?string $bankTransaction = null): PasoRapidoService
    {
        $service = Mockery::mock(PasoRapidoService::class);
        $service->shouldReceive('confirmPayment')->once()->withArgs(
            fn (PaymentConfirmData $data) => $bankTransaction === null || $data->bankTransaction === $bankTransaction
        )->andReturn(new PaymentConfirmResponse(
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

<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Jobs\RetryPaymentReversalJob;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Connectors\Portal\Concerns\BuildsEnrollmentResponses;
use Tests\TestCase;

final class RetryPaymentReversalJobTest extends TestCase
{
    use BuildsEnrollmentResponses;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'ecosystem'];

    public function testRetryReleasesTheHoldAndClearsThePendingMarker(): void
    {
        $payment = $this->seedPendingPayment(PaymentStatusEnum::FAILED);
        $service = $this->bindPortalProcessor();
        $service->shouldReceive('reversePayment')->once()->andReturn(['id' => 'reverse-1']);

        new RetryPaymentReversalJob(app(Apps::class), $payment)->handle();

        $payment->refresh();
        $this->assertFalse($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertTrue($payment->paymentLogs()->where('event_type', 'payment_reversed')->exists());
    }

    public function testAGatewayFailureKeepsThePendingMarkerCountsTheAttemptAndThrows(): void
    {
        $payment = $this->seedPendingPayment(PaymentStatusEnum::FAILED);
        $service = $this->bindPortalProcessor();
        $service->shouldReceive('reversePayment')->once()->andThrow(new RuntimeException('gateway down'));

        try {
            new RetryPaymentReversalJob(app(Apps::class), $payment)->handle();
            $this->fail('The job must throw so the queue retries it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gateway down', $e->getMessage());
        }

        $payment->refresh();
        $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        $this->assertSame(2, $payment->metadata['reversal_attempts']);
        $this->assertSame('gateway down', $payment->metadata['reversal_last_error']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertTrue($payment->paymentLogs()->where('event_type', 'payment_reversal_failed')->exists());
    }

    public function testFinalStatusesAndClearedPaymentsAreSkipped(): void
    {
        $service = $this->bindPortalProcessor();
        $service->shouldNotReceive('reversePayment');

        foreach ([PaymentStatusEnum::AUTHORIZED, PaymentStatusEnum::REVERSED, PaymentStatusEnum::PAID, PaymentStatusEnum::CANCELLED] as $status) {
            $payment = $this->seedPendingPayment($status);
            new RetryPaymentReversalJob(app(Apps::class), $payment)->handle();
        }

        $cleared = $this->seedPendingPayment(PaymentStatusEnum::FAILED);
        $cleared->addMetadata([RetryPaymentReversalJob::PENDING_KEY => false]);
        $cleared->saveQuietly();
        new RetryPaymentReversalJob(app(Apps::class), $cleared)->handle();

        $this->addToAssertionCount(1);
    }

    public function testFailedLogsTheExhaustedEvent(): void
    {
        $payment = $this->seedPendingPayment(PaymentStatusEnum::FAILED);

        new RetryPaymentReversalJob(app(Apps::class), $payment)->failed(new RuntimeException('gave up'));

        $log = $payment->paymentLogs()->where('event_type', 'payment_reversal_exhausted')->firstOrFail();
        $this->assertSame('gave up', $log->metadata['error']);
        $this->assertSame(6, $log->metadata['tries']);
    }

    public function testTheUniqueIdIsThePaymentId(): void
    {
        $payment = $this->seedPendingPayment(PaymentStatusEnum::FAILED);

        $job = new RetryPaymentReversalJob(app(Apps::class), $payment);

        $this->assertSame((string) $payment->getId(), $job->uniqueId());
    }

    public function testAProcessorWithoutHoldReleaseFailsTheJobWithoutRetrying(): void
    {
        $payment = $this->seedPendingPayment(PaymentStatusEnum::FAILED);
        $processor = Mockery::mock(PaymentProcessorInterface::class);
        $processor->shouldReceive('name')->andReturn('legacy');
        $this->app->bind('payment_processor.portal', fn (): PaymentProcessorInterface => $processor);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('fail')->once();

        $job = new RetryPaymentReversalJob(app(Apps::class), $payment);
        $job->setJob($queueJob);
        $job->handle();

        $this->assertTrue($payment->fresh()->metadata[RetryPaymentReversalJob::PENDING_KEY]);
    }

    public function testAFailedHoldReversalAfterAuthorizeMarksPendingAndDispatchesTheRetry(): void
    {
        Bus::fake([RetryPaymentReversalJob::class]);
        $payment = $this->seedPendingPayment(PaymentStatusEnum::WAITING_DEVICE_DATA);
        $payment->metadata = null;
        $payment->payment_intent_id = 'setup-1';
        $payment->saveOrFail();
        $payment->payable->set('auth_session_id', 'ref-1');
        $service = Mockery::mock(EchoPayService::class);
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'eci' => '05',
                'eciRaw' => '05',
                'indicator' => 'vbv',
                'authenticationResult' => '0',
            ]),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
            'paymentInformation' => ['card' => ['type' => 'VISA']],
        ]);
        $service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-1',
            'status' => 'AUTHORIZED_RISK_DECLINED',
        ]);
        $service->shouldReceive('reversePayment')->once()->andThrow(new EchoPayException(message: 'down', code: 503));
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );

        $processor->finalizeChallenge($payment, $payment->payable);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame('intent-1', $payment->payment_intent_id);
        $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        $this->assertSame(1, $payment->metadata['reversal_attempts']);
        Bus::assertDispatched(
            RetryPaymentReversalJob::class,
            function (RetryPaymentReversalJob $job) use ($payment): bool {
                $persisted = $job->payment->fresh();

                return $job->payment->getId() === $payment->getId()
                    && $job->delay !== null
                    && $persisted->status === PaymentStatusEnum::FAILED->value
                    && $persisted->metadata[RetryPaymentReversalJob::PENDING_KEY] === true;
            }
        );
    }

    public function testNoAuthorizeIdMeansNoPendingMarkerAndNoDispatch(): void
    {
        Bus::fake([RetryPaymentReversalJob::class]);
        $payment = $this->seedPendingPayment(PaymentStatusEnum::WAITING_DEVICE_DATA);
        $payment->metadata = null;
        $payment->saveOrFail();
        $payment->payable->set('auth_session_id', 'ref-1');
        $service = Mockery::mock(EchoPayService::class);
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'eci' => '05',
                'eciRaw' => '05',
                'indicator' => 'vbv',
                'authenticationResult' => '0',
            ]),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
            'paymentInformation' => ['card' => ['type' => 'VISA']],
        ]);
        $service->shouldReceive('authorizePayment')->once()->andReturn(['status' => 'PARTIAL_AUTHORIZED']);
        $service->shouldNotReceive('reversePayment');
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );

        $processor->finalizeChallenge($payment, $payment->payable);

        Bus::assertNotDispatched(RetryPaymentReversalJob::class);
        $this->assertArrayNotHasKey(RetryPaymentReversalJob::PENDING_KEY, $payment->refresh()->metadata ?? []);
    }

    private function bindPortalProcessor(): MockInterface
    {
        $service = Mockery::mock(EchoPayService::class);
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );
        $this->app->bind('payment_processor.portal', fn (): PortalProcessor => $processor);

        return $service;
    }

    private function seedPendingPayment(PaymentStatusEnum $status): Payments
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'status' => OrderStatusEnum::PENDING->value,
                'total_gross_amount' => 100.0,
                'total_net_amount' => 100.0,
            ]);

        $payment = new Payments();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $user->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->concept = 'Payment ' . $order->reference;
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = $status->value;
        $payment->payment_intent_id = 'intent-1';
        $payment->processor = 'portal';
        $payment->is_deleted = false;
        $payment->metadata = [
            RetryPaymentReversalJob::PENDING_KEY => true,
            'reversal_attempts' => 1,
            'reversal_amount' => 100.0,
            'reversal_reason' => 'Authorization not acceptable: RISK_DECLINED',
        ];
        $payment->saveOrFail();

        return $payment;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Jobs\RetryPaymentReversalJob;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class RetryPaymentReversalJobTest extends TestCase
{
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
        $shopperMessage = __('payment_errors.PROCESSOR_UNAVAILABLE', [], 'es');

        try {
            new RetryPaymentReversalJob(app(Apps::class), $payment)->handle();
            $this->fail('The job must throw so the queue retries it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($shopperMessage, $e->getMessage());
        }

        $payment->refresh();
        $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        $this->assertSame(2, $payment->metadata['reversal_attempts']);
        $this->assertSame($shopperMessage, $payment->metadata['reversal_last_error']);
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

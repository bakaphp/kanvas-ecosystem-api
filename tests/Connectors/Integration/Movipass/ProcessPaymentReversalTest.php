<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Connectors\Movipass\Jobs\RetryPaymentReversalJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ProcessPaymentReversalTest extends TestCase
{
    public function testKeepsPaymentAuthorizedAndQueuesRetryWhenGatewayFailsTheReversal(): void
    {
        Bus::fake([RetryPaymentReversalJob::class]);

        [$app, $order, $payment] = $this->makeAuthorizedOrder();

        $processor = Mockery::mock(PortalPaymentProcessor::class);
        $processor->shouldReceive('processPayment')
            ->once()
            ->andReturn(['status' => 'success', 'message' => 'Payment successful', 'data' => []]);
        $processor->shouldReceive('capturePayment')
            ->once()
            ->andReturn(['status' => 'error', 'message' => 'capture declined', 'data' => []]);
        $processor->shouldReceive('reversePayment')
            ->once()
            ->andReturn(['status' => 'error', 'message' => 'EchoPay 503 Service Unavailable', 'data' => []]);

        $result = new ProcessPaymentAction($app, $payment, $order, $processor)
            ->execute($this->consumerAuthentication());

        $this->assertSame(PaymentStatusEnum::FAILED->value, $result['status']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        $this->assertSame(1, $payment->metadata['reversal_attempts']);
        $this->assertSame('EchoPay 503 Service Unavailable', $payment->metadata['reversal_last_error']);
        $this->assertTrue($payment->paymentLogs()->where('event_type', 'payment_reversal_failed')->exists());

        Bus::assertDispatched(
            RetryPaymentReversalJob::class,
            fn (RetryPaymentReversalJob $job) => $job->payment->getId() === $payment->getId() && $job->delay !== null
        );
    }

    public function testRetryJobClearsPendingFlagWhenGatewayAcceptsTheReversal(): void
    {
        [$app, $order, $payment, $bankTransaction] = $this->makeAuthorizedOrder([
            RetryPaymentReversalJob::PENDING_KEY => true,
            'reversal_attempts' => 1,
        ]);

        $processor = Mockery::mock(PortalPaymentProcessor::class);
        $processor->shouldReceive('reversePayment')
            ->once()
            ->withArgs(fn (Payments $p, Order $o, string $transaction, string $reason) => $transaction === $bankTransaction && $reason === 'retry')
            ->andReturn(['status' => 'success', 'message' => 'Payment reversed successfully', 'data' => []]);

        $job = new RetryPaymentReversalJob($app, $payment, $order, 'retry');
        $job->paymentProcessor = $processor;
        $job->handle();

        $this->assertFalse($payment->fresh()->metadata[RetryPaymentReversalJob::PENDING_KEY]);
    }

    public function testRetryJobThrowsSoTheQueueRetriesWhenGatewayFailsAgain(): void
    {
        [$app, $order, $payment] = $this->makeAuthorizedOrder([
            RetryPaymentReversalJob::PENDING_KEY => true,
            'reversal_attempts' => 1,
        ]);

        $processor = Mockery::mock(PortalPaymentProcessor::class);
        $processor->shouldReceive('reversePayment')
            ->once()
            ->andReturn(['status' => 'error', 'message' => 'still down', 'data' => []]);

        $job = new RetryPaymentReversalJob($app, $payment, $order, 'retry');
        $job->paymentProcessor = $processor;

        try {
            $job->handle();
            $this->fail('Expected RuntimeException so the queue retries');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still down', $e->getMessage());
        }

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
        $this->assertSame(2, $payment->metadata['reversal_attempts']);
    }

    public function testRetryJobSkipsPaymentsAlreadyReversed(): void
    {
        [$app, $order, $payment] = $this->makeAuthorizedOrder([RetryPaymentReversalJob::PENDING_KEY => true]);
        $payment->update(['status' => PaymentStatusEnum::REVERSED->value]);

        $processor = Mockery::mock(PortalPaymentProcessor::class);
        $processor->shouldNotReceive('reversePayment');

        $job = new RetryPaymentReversalJob($app, $payment, $order, 'retry');
        $job->paymentProcessor = $processor;
        $job->handle();

        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->fresh()->status);
    }

    /**
     * An order whose card already holds an EchoPay authorization.
     *
     * @return array{Apps, Order, Payments, string}
     */
    private function makeAuthorizedOrder(array $paymentMetadata = []): array
    {
        $app = app(Apps::class);
        $user = Auth::user();
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
            ->create();
        $order->setOrderType('movipass');

        $bankTransaction = '7894030888' . rand(100000000000, 999999999999);
        $order->set(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $bankTransaction);

        $payment = $order->payments()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'amount' => $order->getTotalAmount(),
            'payment_date' => date('Y-m-d'),
            'concept' => 'test',
            'currency' => 'DOP',
            'status' => PaymentStatusEnum::AUTHORIZED->value,
            'payment_method' => 'card',
            'payment_intent_id' => $bankTransaction,
            'metadata' => $paymentMetadata,
        ]);

        return [$app, $order, $payment, $bankTransaction];
    }

    private function consumerAuthentication(): ConsumerAuthentication
    {
        return ConsumerAuthentication::from([
            'indicator' => 'vbv',
            'eci' => '05',
            'token' => 'AxjzbwSTlSvEI+byinVHAKUBTyD9dO6A1h04goIQyaSZejFcRGKBWAAAXBJS',
            'strongAuthentication' => ['OutageExemptionIndicator' => '0'],
            'cavv' => 'AAIBBYNoEwAAACcKhAJkdQAAAAA=',
            'xid' => 'AAIBBYNoEwAAACcKhAJkdQAAAAA=',
            'paresStatus' => 'Y',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Tests\Connectors\Portal\Concerns\BuildsEnrollmentResponses;
use Tests\TestCase;

final class PortalProcessorDeclineLogTest extends TestCase
{
    use BuildsEnrollmentResponses;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'ecosystem'];

    public function testCaptureDeclineFillsThePaymentLogErrorColumns(): void
    {
        $payment = $this->seedAuthorizedPayment();
        $service = Mockery::mock(EchoPayService::class);
        $service->shouldReceive('capturePayment')->once()->andThrow(new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => [
                'errorInformation' => ['reason' => 'INSUFFICIENT_FUND', 'message' => 'Decline - Insufficient funds in the account.'],
                'processorInformation' => ['responseCode' => '51'],
                'paymentInsightsInformation' => ['responseInsights' => ['category' => 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME']],
            ]]
        ));
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );

        $processor->capture($payment, $payment->payable);

        $log = PaymentLogs::query()
            ->where('payments_id', $payment->getId())
            ->where('event_type', 'payment_error')
            ->firstOrFail();

        $this->assertSame('INSUFFICIENT_FUND', $log->error_code);
        $this->assertSame('Decline - Insufficient funds in the account.', $log->error_message);
        $this->assertSame('51', $log->processor_response_code);
        $this->assertSame('ISSUER_CANNOT_APPROVE_AT_THIS_TIME', $log->response_insight);
        $this->assertSame('capture_payment', $log->metadata['context']);
    }

    public function testAuthenticationFailureFillsThePaymentLogErrorColumns(): void
    {
        $payment = $this->seedAuthorizedPayment();
        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
        $payment->saveOrFail();
        $payment->payable->set('auth_session_id', 'ref-1');
        $service = Mockery::mock(EchoPayService::class);
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'authenticationTransactionId' => 'auth-tx-9',
                'paresStatus' => 'N',
            ]),
            'id' => 'enroll-9',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
            'errorInformation' => [
                'reason' => 'CONSUMER_AUTHENTICATION_FAILED',
                'message' => 'The cardholder could not be authenticated.',
            ],
        ]);
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );

        $result = $processor->finalizeChallenge($payment, $payment->payable);

        $log = PaymentLogs::query()
            ->where('payments_id', $payment->getId())
            ->where('event_type', 'payment_authentication_failed')
            ->firstOrFail();

        $this->assertFalse($result->success);
        $this->assertSame(OrderStatusEnum::FAILED->value, $payment->payable->refresh()->status);
        $this->assertSame('CONSUMER_AUTHENTICATION_FAILED', $log->error_code);
        $this->assertSame('The cardholder could not be authenticated.', $log->error_message);
    }

    public function testAnUnacceptableHoldIsReversedAndLogsItsOwnErrorCode(): void
    {
        $payment = $this->seedAuthorizedPayment();
        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
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
        $service->shouldReceive('reversePayment')->once()->andReturn(['id' => 'reverse-1']);
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );

        $result = $processor->finalizeChallenge($payment, $payment->payable);

        $log = PaymentLogs::query()
            ->where('payments_id', $payment->getId())
            ->where('event_type', '3ds_authorize_declined')
            ->firstOrFail();

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
        $this->assertSame('RISK_DECLINED', $log->error_code);
        $this->assertSame('The gateway authorized the payment but its risk rules declined it.', $log->error_message);
        $this->assertTrue($log->metadata['hold_reversed']);
    }

    private function seedAuthorizedPayment(): Payments
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
        $payment->status = PaymentStatusEnum::AUTHORIZED->value;
        $payment->payment_intent_id = 'intent-1';
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }
}

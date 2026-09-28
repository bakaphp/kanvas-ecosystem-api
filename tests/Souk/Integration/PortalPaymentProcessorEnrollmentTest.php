<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class PortalPaymentProcessorEnrollmentTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    public function testPendingAuthenticationKeepsTheOrderAlive(): void
    {
        [$payment, $order] = $this->seedPayment();

        $result = $this->requestUserValidation($payment, $this->enrollment('PENDING_AUTHENTICATION'));

        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result['status']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->refresh()->status);
        $this->assertSame(OrderStatusEnum::PENDING->value, $order->refresh()->status);
        $this->assertSame('https://centinelapi.cardinalcommerce.com/V2/Cruise/StepUp', $result['data']->stepUpUrl);
    }

    public function testChallengeMessageDoesNotLeakTheGatewayError(): void
    {
        [$payment] = $this->seedPayment();

        $result = $this->requestUserValidation($payment, $this->enrollment('PENDING_AUTHENTICATION'));

        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result['message']);
        $this->assertStringNotContainsString('Payer Authentication', $result['message']);
    }

    public function testAuthenticationFailedStillSurfacesTheGatewayError(): void
    {
        [$payment, $order] = $this->seedPayment();

        $result = $this->requestUserValidation($payment, $this->enrollment('AUTHENTICATION_FAILED', [
            'reason' => 'CONSUMER_AUTHENTICATION_FAILED',
            'message' => 'The cardholder could not be authenticated.',
        ]));

        $this->assertSame(PaymentStatusEnum::FAILED->value, $result['status']);
        $this->assertStringContainsString('The cardholder could not be authenticated.', $result['message']);
        $this->assertSame(OrderStatusEnum::FAILED->value, $order->refresh()->status);
    }

    public function testAuthenticationFailureFillsTheLogErrorColumns(): void
    {
        [$payment] = $this->seedPayment();

        $this->requestUserValidation($payment, $this->enrollment('AUTHENTICATION_FAILED', [
            'reason' => 'CONSUMER_AUTHENTICATION_FAILED',
            'message' => 'The cardholder could not be authenticated.',
        ]));

        $log = PaymentLogs::query()
            ->where('payments_id', $payment->getId())
            ->where('event_type', 'payment_authentication_failed')
            ->firstOrFail();

        $this->assertSame('CONSUMER_AUTHENTICATION_FAILED', $log->error_code);
        $this->assertSame('The cardholder could not be authenticated.', $log->error_message);
    }

    public function testAuthTransactionIdIsPersistedAtEnrollment(): void
    {
        [$payment, $order] = $this->seedPayment();

        $this->requestUserValidation($payment, $this->enrollment('PENDING_AUTHENTICATION'));

        $this->assertSame(
            'test-auth-transaction-id',
            $order->refresh()->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value)
        );
    }

    public function testAuthTransactionIdFromTheWebhookIsNotOverwritten(): void
    {
        [$payment, $order] = $this->seedPayment();
        $order->set(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value, 'from-webhook');

        $this->requestUserValidation($payment, $this->enrollment('PENDING_AUTHENTICATION'));

        $this->assertSame(
            'from-webhook',
            $order->refresh()->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value)
        );
    }

    public function testUnknownEnrollmentStatusFailsWithoutThrowing(): void
    {
        [$payment, $order] = $this->seedPayment();

        $handler = Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing();
        $handler->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $handler);

        $result = $this->requestUserValidation($payment, $this->enrollment('AUTHENTICATION_BYPASSED'));

        $this->assertSame(PaymentStatusEnum::FAILED->value, $result['status']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
        $this->assertSame(OrderStatusEnum::FAILED->value, $order->refresh()->status);
    }

    /**
     * @return array{Payments, Order}
     */
    private function seedPayment(): array
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
        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return [$payment, $order];
    }

    private function requestUserValidation(Payments $payment, array $enrollmentData): array
    {
        $processor = new ReflectionClass(PortalPaymentProcessor::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod($processor, 'requestUserValidation')
            ->invoke($processor, $payment, $enrollmentData);
    }

    private function enrollment(string $status, ?array $errorInformation = null): array
    {
        return [
            'clientReferenceInformation' => ['code' => 'test-order-code'],
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                'indicator' => null,
                'authenticationTransactionId' => 'test-auth-transaction-id',
                'eciRaw' => null,
                'authenticationResult' => null,
                'strongAuthentication' => ['OutageExemptionIndicator' => '0'],
                'authenticationStatusMsg' => null,
                'eci' => null,
                'token' => 'test-consumer-auth-token',
                'accessToken' => 'test-access-token',
                'cavv' => null,
                'paresStatus' => 'C',
                'xid' => null,
                'directoryServerTransactionId' => '00000000-0000-4000-8000-000000000001',
                'threeDSServerTransactionId' => '00000000-0000-4000-8000-000000000002',
                'specificationVersion' => '2.2.0',
                'acsTransactionId' => '00000000-0000-4000-8000-000000000003',
                'ucafCollectionIndicator' => '',
                'ucafAuthenticationData' => '',
                'challengeRequired' => 'Y',
                'acsUrl' => 'https://acs.example.test/3ds/challenge/browserCreq',
                'acsReferenceNumber' => 'test-acs-reference',
                'stepUpUrl' => 'https://centinelapi.cardinalcommerce.com/V2/Cruise/StepUp',
                'pareq' => 'test-pareq',
                'veresEnrolled' => 'Y',
                'acsOperatorID' => 'test-acs-operator',
            ]),
            'errorInformation' => $errorInformation ?? [
                'reason' => 'CONSUMER_AUTHENTICATION_REQUIRED',
                'message' => 'The cardholder is enrolled in Payer Authentication. Please authenticate the cardholder before continuing with the transaction.',
            ],
            'id' => 'test-request-id',
            'paymentInformation' => ['card' => ['bin' => '539416', 'type' => 'MASTERCARD']],
            'status' => $status,
            'submitTimeUtc' => '2026-08-13T21:06:11Z',
        ];
    }
}

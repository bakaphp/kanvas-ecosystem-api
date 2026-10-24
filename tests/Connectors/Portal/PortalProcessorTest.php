<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal;

use DomainException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\Client as EchoPayClient;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\DataTransferObject\PaymentFailure;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Observers\PaymentObserver;
use Kanvas\Users\Models\Users;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use Tests\TestCase;

class PortalProcessorTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;
    private EchoPayService $service;
    private PortalProcessor $processor;
    private array $loggedEvents = [];
    private array $orderUpdates = [];
    private array $appSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = Mockery::mock(app(Apps::class))->makePartial();
        $this->appSettings = [
            'ECHO_PAY_MERCHANT_ID' => 'merchant-default',
            'ECHO_PAY_MERCHANT_KEY' => 'key-default',
            'ECHO_PAY_MERCHANT_SECRET' => 'secret-default',
        ];
        $this->currentApp->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => $this->appSettings[$key] ?? null);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->service = Mockery::mock(EchoPayService::class);
        $this->processor = new PortalProcessor(
            app: $this->currentApp,
            company: $this->currentCompany,
            service: $this->service,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }


    public function testNameReturnsPortal(): void
    {
        $this->assertSame('portal', $this->processor->name());
    }

    public function testAuthorizeThrowsBecauseFlowIsAlways3DS(): void
    {
        $this->expectException(DomainException::class);
        $this->processor->authorize($this->buildPaymentMock(PaymentStatusEnum::PENDING->value), $this->buildOrderMock());
    }

    #[DataProvider('storedStatusProvider')]
    public function testVerifyReturnsTheStoredStatusWithoutCallingTheGateway(string $status, bool $expectedSuccess): void
    {
        $payment = $this->buildPaymentMock($status, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);

        $result = $this->processor->verify($payment, $this->buildOrderMock());

        $this->assertSame($expectedSuccess, $result->success);
        $this->assertSame($status, $result->raw['status']);
        $this->assertStringContainsString($status, $result->message);
        $this->assertSame('tx-1', $result->transactionId);
    }

    public static function storedStatusProvider(): array
    {
        return [
            'authorized' => [PaymentStatusEnum::AUTHORIZED->value, true],
            'paid' => [PaymentStatusEnum::PAID->value, true],
            'pending' => [PaymentStatusEnum::PENDING->value, false],
            'processing' => [PaymentStatusEnum::PROCESSING->value, false],
            'failed' => [PaymentStatusEnum::FAILED->value, false],
            'reversed' => [PaymentStatusEnum::REVERSED->value, false],
            'cancelled' => [PaymentStatusEnum::CANCELLED->value, false],
        ];
    }

    public function testTokenizeDelegatesToService(): void
    {
        $expected = new TokenizeResult(
            success: true,
            message: 'ok',
            token: 'mock-token',
            lastFour: '1111',
            brand: 'visa',
        );

        $user = $this->buildUserMock();
        $this->service->shouldReceive('tokenize')->once()->with(['number' => '4111111111111111'], $user)->andReturn($expected);

        $result = $this->processor->tokenize(['number' => '4111111111111111'], $user);

        $this->assertSame($expected, $result);
    }

    public function testDeleteTokenDelegatesToService(): void
    {
        $this->service->shouldReceive('deleteToken')->once()->with('mock-token')->andReturn(true);

        $this->assertTrue($this->processor->deleteToken('mock-token'));
    }


    public function testStartChallengeHappyPathPersistsMetadataAndTransitions(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();

        $this->service->shouldReceive('setupPayer')->once()->andReturn([
            'consumerAuthenticationInformation' => [
                'accessToken' => 'tok-1',
                'deviceDataCollectionUrl' => 'https://mock/3ds',
                'referenceId' => 'ref-1',
                'token' => 't-1',
            ],
            'id' => 'setup-1',
            'status' => 'COMPLETED',
        ]);

        $result = $this->processor->startChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $result->status);
        $this->assertSame('setup-1', $result->data['transaction_id']);
        $this->assertSame('ref-1', $result->data['reference_id']);
        $this->assertSame('https://mock/3ds', $result->data['device_data_collection_url']);

        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $payment->status);
        $this->assertSame('setup-1', $payment->payment_intent_id);
        $this->assertSame('ref-1', $order->customFields['auth_session_id']);
        $this->assertSame('ref-1', $payment->metadata['data']['echo_pay_reference_id']);
    }

    public function testStartChallengeWithoutPaymentInstrumentFailsCleanly(): void
    {
        $paymentMethod = $this->buildPaymentMethodMock(stripeCardId: '');
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value, $paymentMethod);
        $order = $this->buildOrderMock();

        $this->service->shouldNotReceive('setupPayer');

        $result = $this->processor->startChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $result->status);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
    }


    public function testStartChallengeWithRawCardContextExplainsThePortalTokenizationRequirement(): void
    {
        $paymentMethod = $this->buildPaymentMethodMock(stripeCardId: '');
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value, $paymentMethod);
        $order = $this->buildOrderMock();
        $this->service->shouldNotReceive('setupPayer');

        $result = $this->processor->startChallenge($payment, $order, ['card' => ['number' => '4111111111111111']]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('createPaymentMethod', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
    }

    public function testStartChallengeExposesTheLegacyCamelCaseKeysNextToTheSnakeCaseOnes(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();
        $this->service->shouldReceive('setupPayer')->once()->andReturn([
            'consumerAuthenticationInformation' => [
                'accessToken' => 'tok-1',
                'deviceDataCollectionUrl' => 'https://mock/3ds',
                'referenceId' => 'ref-1',
            ],
            'id' => 'setup-1',
        ]);

        $data = $this->processor->startChallenge($payment, $order)->data;

        $this->assertSame('https://mock/3ds', $data['deviceDataUrl']);
        $this->assertSame('tok-1', $data['accessToken']);
        $this->assertSame('ref-1', $data['referenceId']);
        $this->assertSame($data['device_data_collection_url'], $data['deviceDataUrl']);
        $this->assertSame($data['access_token'], $data['accessToken']);
        $this->assertSame($data['reference_id'], $data['referenceId']);
    }

    public function testChallengeResultExposesTheLegacyCamelCaseKeysNextToTheSnakeCaseOnes(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->challengeEnrollmentWithAuthTransaction('auth-tx-1'));

        $data = $this->processor->finalizeChallenge($payment, $order)->data;

        $this->assertSame('https://acs.test/challenge', $data['acsUrl']);
        $this->assertSame('https://acs.test/step-up', $data['stepUpUrl']);
        $this->assertSame('tok-1', $data['accessToken']);
        $this->assertSame('auth-tx-1', $data['authenticationTransactionId']);
        $this->assertSame('enroll-1', $data['auth_transaction_id']);
        $this->assertSame($data['acs_url'], $data['acsUrl']);
        $this->assertSame($data['step_up_url'], $data['stepUpUrl']);
        $this->assertSame($data['access_token'], $data['accessToken']);
        $this->assertSame('auth-tx-1', $data['consumer_authentication']['authenticationTransactionId']);
    }

    public function testChallengeResultCarriesTheRedirectUrlSettingAsReturnUrl(): void
    {
        $this->appSettings['ECHO_PAY_REDIRECT_URL'] = 'https://front.test/3ds/return';
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->challengeEnrollmentWithAuthTransaction('auth-tx-1'));

        $data = $this->processor->finalizeChallenge($payment, $order)->data;

        $this->assertSame('https://front.test/3ds/return', $data['returnUrl']);
    }

    public function testChallengeResultReturnUrlIsAnEmptyStringWhenTheSettingIsAbsent(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->challengeEnrollmentWithAuthTransaction('auth-tx-1'));

        $data = $this->processor->finalizeChallenge($payment, $order)->data;

        $this->assertSame('', $data['returnUrl']);
    }

    public function testFinalizeChallengeFrictionlessRunsAuthorize(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);

        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
            'paymentInformation' => ['card' => ['type' => 'VISA']],
        ]);

        $this->service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-1',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['transactionId' => 'tx-1'],
        ]);

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $result->status);
        $this->assertSame('intent-1', $result->data['intent_id']);
        $this->assertSame('tx-1', $result->data['transaction_id']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertSame('tx-1', $payment->metadata['data']['echo_pay_authorize_transaction_id']);
    }

    public function testFinalizeChallengeReturnsChallengeWhenEnrollmentRequiresIt(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);

        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'acsUrl' => 'https://acs.test/challenge',
                'pareq' => 'pareq-payload',
                'accessToken' => 'tok-1',
                'stepUpUrl' => 'https://acs.test/step-up',
            ]),
            'id' => 'enroll-2',
            'status' => EchoPayStatusEnum::PENDING_AUTHENTICATION->value,
        ]);

        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame('https://acs.test/challenge', $result->data['acs_url']);
        $this->assertSame('pareq-payload', $result->data['pareq']);
        $this->assertSame('enroll-2', $result->data['auth_transaction_id']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame('enroll-2', $payment->metadata['enrollment_data']['id']);
    }

    public function testFinalizeChallengeMarksFailedOnHardAuthenticationFailure(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);

        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'enroll-3',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
        ]);

        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
    }

    public function testFinalizeChallengeMissingReferenceIdFailsCleanly(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock();

        $this->service->shouldNotReceive('checkPayerEnrollment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
    }


    public function testFinalizeChallengeFromPendingAuthorizationValidatesAndAuthorizes(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'enroll-1']);

        $this->service->shouldReceive('validatePayerAuthResult')->once()->with('enroll-1', Mockery::any(), Mockery::any())->andReturn([
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'validate-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
        ]);

        $this->service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-2',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['transactionId' => 'tx-2'],
        ]);

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertSame('tx-2', $result->data['transaction_id']);
    }

    public function testFinalizeChallengeFromPendingAuthorizationPassesThroughInvalidEciWhileThePayerIsStillPending(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'enroll-1']);
        $this->attachOrder($payment, $order);

        $this->service->shouldReceive('validatePayerAuthResult')->once()->andReturn([
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'eci' => '07',
                'eciRaw' => '07',
            ]),
            'id' => 'validate-2',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
        ]);

        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
    }

    public function testFinalizeChallengeInUnexpectedStateReturnsFailureWithoutTransitioning(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $order = $this->buildOrderMock();

        $this->service->shouldNotReceive('checkPayerEnrollment');
        $this->service->shouldNotReceive('validatePayerAuthResult');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }


    public function testCaptureFromAuthorizedTransitionsToPaid(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();

        $this->service->shouldReceive('capturePayment')->once()->andReturn([
            'id' => 'capture-1',
            'status' => 'PENDING',
            'transactionId' => 'tx-1',
        ]);

        $result = $this->processor->capture($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('capture-1', $result->transactionId);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testCaptureRefusesNonAuthorizedPayment(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();

        $this->service->shouldNotReceive('capturePayment');

        $result = $this->processor->capture($payment, $order);

        $this->assertFalse($result->success);
    }

    public function testRefundFromPaidTransitionsToReversed(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();

        $this->service->shouldReceive('reversePayment')->once()->andReturn([
            'id' => 'reverse-1',
            'status' => 'REVERSED',
            'transactionId' => 'tx-1',
        ]);

        $result = $this->processor->refund(
            $payment,
            $order,
            amount: null,
            context: ['reason' => 'customer-requested']
        );

        $this->assertTrue($result->success);
        $this->assertSame('reverse-1', $result->transactionId);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testRefundRefusesAPaymentThatWasNeverAuthorized(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();

        $this->service->shouldNotReceive('reversePayment');

        $result = $this->processor->refund($payment, $order);

        $this->assertFalse($result->success);
    }

    public function testVoidFromAuthorizedTransitionsToCancelled(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();

        $this->service->shouldReceive('reversePayment')->once()->andReturn([
            'id' => 'reverse-2',
            'status' => 'REVERSED',
            'transactionId' => 'tx-1',
        ]);

        $result = $this->processor->void($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
    }

    public function testVoidRefusesNonAuthorizedPayment(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $order = $this->buildOrderMock();

        $this->service->shouldNotReceive('reversePayment');

        $result = $this->processor->void($payment, $order);

        $this->assertFalse($result->success);
    }

    public function testAuthorizeDeclineLogsTheGatewayFieldsAndFailsPayment(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->expectReports(0);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andThrow($this->decline());

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame('Fondos insuficientes en la cuenta.', $result->message);
        $log = $this->loggedEvent('3ds_authorize_failed');
        $this->assertEquals($this->declineFailure(), $log['failure']);
    }

    public function testAuthorizeDeclineStoresTheFailureMetadataUnderData(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andThrow($this->decline());

        $this->processor->finalizeChallenge($payment, $order);

        $data = $payment->metadata['data'];
        $this->assertSame('Payment not approved', $data['error']);
        $this->assertSame($this->decline()->getErrorBody(), $data['echopay_error']);
        $this->assertSame('Fondos insuficientes en la cuenta.', $data['user_message']);
        $this->assertNotEmpty($data['echopay_error_timestamp']);
    }

    public function testAuthorizeNonEchoPayFailureUsesTheGatewayBodyMessage(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->expectReports(1);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andThrow($this->guzzleFailure());

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('Invalid card number', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertEquals(
            new PaymentFailure(code: 'RequestException', message: 'Invalid card number'),
            $this->loggedEvent('3ds_authorize_failed')['failure']
        );
        $this->assertEquals(['field' => 'pan'], $payment->metadata['data']['message_body']);
        $this->assertSame('Invalid card number', $payment->metadata['data']['error']);
    }

    public function testResolveErrorMessageFallsBackToTheExceptionMessage(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->expectReports(1);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andThrow(new RuntimeException('socket closed'));

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('socket closed', $result->message);
        $this->assertSame([], $payment->metadata['data']['message_body']);
    }

    #[DataProvider('reportingProvider')]
    public function testOnlyGatewayFailuresAreReported(int $statusCode, int $expectedReports): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();
        $this->expectReports($expectedReports);
        $this->service->shouldReceive('capturePayment')->once()->andThrow(
            new EchoPayException(message: 'Payment not approved', code: $statusCode, errorBody: [])
        );

        $this->assertFalse($this->processor->capture($payment, $order)->success);
    }

    public static function reportingProvider(): array
    {
        return [
            'decline 400' => [400, 0],
            'decline 402' => [402, 0],
            'no response' => [0, 1],
            'server error 500' => [500, 1],
            'bad gateway 502' => [502, 1],
        ];
    }

    public function testCaptureDeclineLogsPaymentErrorWithFailureAndContext(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();
        $this->service->shouldReceive('capturePayment')->once()->andThrow($this->decline());

        $result = $this->processor->capture($payment, $order);

        $this->assertFalse($result->success);
        $log = $this->loggedEvent('payment_error');
        $this->assertSame('capture_payment', $log['context']['context']);
        $this->assertSame('EchoPayException', $log['context']['error_type']);
        $this->assertSame('tx-1', $log['context']['authorize_transaction_id']);
        $this->assertEquals($this->declineFailure(), $log['failure']);
    }

    public function testCaptureNonEchoPayFailureLogsClassNameAsCode(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();
        $this->expectReports(1);
        $this->service->shouldReceive('capturePayment')->once()->andThrow(new RuntimeException('boom'));

        $this->processor->capture($payment, $order);

        $log = $this->loggedEvent('payment_error');
        $this->assertEquals(new PaymentFailure(code: 'RuntimeException', message: 'boom'), $log['failure']);
        $this->assertSame(RuntimeException::class, $log['context']['error_type']);
    }

    public function testRefundFailureLogsPaymentErrorWithFailure(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();
        $this->service->shouldReceive('reversePayment')->once()->andThrow($this->decline());

        $result = $this->processor->refund($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $log = $this->loggedEvent('payment_error');
        $this->assertSame('reverse_payment', $log['context']['context']);
        $this->assertSame('refund', $log['context']['operation']);
        $this->assertEquals($this->declineFailure(), $log['failure']);
    }

    public function testVoidNonEchoPayFailureLogsPaymentErrorAndReports(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $order = $this->buildOrderMock();
        $this->expectReports(1);
        $this->service->shouldReceive('reversePayment')->once()->andThrow(new RuntimeException('boom'));

        $result = $this->processor->void($payment, $order);

        $this->assertFalse($result->success);
        $log = $this->loggedEvent('payment_error');
        $this->assertSame('void', $log['context']['operation']);
        $this->assertEquals(new PaymentFailure(code: 'RuntimeException', message: 'boom'), $log['failure']);
    }

    public function testEnrollmentFailureFailsPaymentAndOrderAndLogsTheStage(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $this->expectReports(0);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andThrow($this->decline());
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame('Fondos insuficientes en la cuenta.', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame(OrderStatusEnum::FAILED->value, $this->orderUpdates['status']);
        $this->assertSame(OrderFulfillmentStatusEnum::CANCELLED->value, $this->orderUpdates['fulfillment_status']);
        $log = $this->loggedEvent('payment_authentication_error');
        $this->assertSame('enrollment', $log['context']['stage']);
        $this->assertSame(100, $log['context']['order_id']);
        $this->assertEquals($this->declineFailure(), $log['failure']);
    }

    public function testEnrollmentFailureStoresTheFailureMetadataAtTopLevel(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andThrow($this->decline());

        $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('Payment not approved', $payment->metadata['error']);
        $this->assertSame($this->decline()->getErrorBody(), $payment->metadata['echopay_error']);
        $this->assertSame('Fondos insuficientes en la cuenta.', $payment->metadata['user_message']);
        $this->assertNotEmpty($payment->metadata['echopay_error_timestamp']);
    }

    public function testEnrollmentNonEchoPayFailureResolvesTheMessageAndReports(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $this->expectReports(1);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andThrow($this->guzzleFailure());

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('Invalid card number', $result->message);
        $this->assertSame('Invalid card number', $payment->metadata['error']);
        $this->assertSame(OrderStatusEnum::FAILED->value, $this->orderUpdates['status']);
        $log = $this->loggedEvent('payment_authentication_error');
        $this->assertSame('enrollment', $log['context']['stage']);
        $this->assertEquals(
            new PaymentFailure(code: 'RequestException', message: 'Invalid card number'),
            $log['failure']
        );
    }

    public function testValidateFailureFailsOrderAndLogsTheValidateStage(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'enroll-1']);
        $this->attachOrder($payment, $order);
        $this->expectReports(1);
        $this->service->shouldReceive('validatePayerAuthResult')->once()->andThrow(new RuntimeException('timeout'));

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame(OrderFulfillmentStatusEnum::CANCELLED->value, $this->orderUpdates['fulfillment_status']);
        $log = $this->loggedEvent('payment_authentication_error');
        $this->assertSame('validate_auth_result', $log['context']['stage']);
        $this->assertEquals(new PaymentFailure(code: 'RuntimeException', message: 'timeout'), $log['failure']);
    }

    public function testEnrollmentAuthenticationFailureCapturesTheGatewayPayload(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $response = [
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'paresStatus' => 'N',
                'authenticationTransactionId' => 'auth-tx-9',
            ]),
            'id' => 'enroll-9',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
            'errorInformation' => [
                'reason' => 'CONSUMER_AUTHENTICATION_FAILED',
                'message' => 'The cardholder could not be authenticated.',
            ],
        ];
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($response);
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame('The cardholder could not be authenticated.', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame($response['id'], $payment->metadata['enrollment_data']['id']);
        $log = $this->loggedEvent('payment_authentication_failed');
        $this->assertSame(EchoPayStatusEnum::AUTHENTICATION_FAILED->value, $log['context']['enrollment_status']);
        $this->assertSame('CONSUMER_AUTHENTICATION_FAILED', $log['context']['reason']);
        $this->assertSame('N', $log['context']['pares_status']);
        $this->assertSame('auth-tx-9', $log['context']['authentication_transaction_id']);
        $this->assertSame('enroll-9', $log['context']['request_id']);
        $this->assertEquals(
            new PaymentFailure(
                code: 'CONSUMER_AUTHENTICATION_FAILED',
                message: 'The cardholder could not be authenticated.'
            ),
            $log['failure']
        );
    }

    public function testEnrollmentAuthenticationFailureWithoutGatewayErrorKeepsTheGenericMessage(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'enroll-3',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
        ]);

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('3DS authentication failed at enrollment.', $result->message);
        $this->assertEquals(
            new PaymentFailure(code: null, message: null),
            $this->loggedEvent('payment_authentication_failed')['failure']
        );
    }

    public function testInvalidEciAfterChallengeCapturesTheGatewayPayload(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'enroll-1']);
        $this->attachOrder($payment, $order);
        $response = [
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'validate-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
            'errorInformation' => ['reason' => 'INVALID_ECI', 'message' => 'Challenge was not completed.'],
        ];
        $this->service->shouldReceive('validatePayerAuthResult')->once()->andReturn($response);
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('Challenge was not completed.', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame($response['id'], $payment->metadata['enrollment_data']['id']);
        $log = $this->loggedEvent('payment_authentication_failed');
        $this->assertSame('INVALID_ECI', $log['context']['reason']);
        $this->assertSame('validate-1', $log['context']['request_id']);
    }

    public function testFinalizeReadsTheAuthTransactionIdTheWebhookOverwroteOnTheOrder(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $validatedWith = null;
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->challengeEnrollmentWithAuthTransaction('auth-tx-1'));
        $this->service->shouldReceive('validatePayerAuthResult')->once()->withArgs(function (string $transactionId) use (&$validatedWith): bool {
            $validatedWith = $transactionId;

            return true;
        })->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-1',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['transactionId' => 'tx-1'],
        ]);

        $this->processor->finalizeChallenge($payment, $order);
        $rememberedByEnrollment = $order->customFields['auth_transaction_id'];
        $order->set('auth_transaction_id', 'webhook-tx');
        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('auth-tx-1', $rememberedByEnrollment);
        $this->assertSame('webhook-tx', $validatedWith);
        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testEnrollmentDoesNotOverwriteAnAuthTransactionIdAlreadyOnTheOrder(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'webhook-tx']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->challengeEnrollmentWithAuthTransaction('auth-tx-1'));

        $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('webhook-tx', $order->customFields['auth_transaction_id']);
    }

    public function testFinalizeWithoutAnAuthTransactionIdLeavesThePaymentPendingForALateWebhook(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldNotReceive('validatePayerAuthResult');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
    }

    public function testStartChallengePersistsTheSetupKeysTheFrontendAndWorkflowRead(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();
        $this->service->shouldReceive('setupPayer')->once()->andReturn([
            'consumerAuthenticationInformation' => [
                'accessToken' => 'tok-1',
                'deviceDataCollectionUrl' => 'https://mock/3ds',
                'referenceId' => 'ref-1',
            ],
            'id' => 'setup-1',
        ]);

        $this->processor->startChallenge($payment, $order);

        $this->assertSame('ref-1', $order->customFields['auth_session_id']);
        $this->assertSame('https://mock/3ds', $order->customFields['device_data_url']);
        $this->assertSame('tok-1', $order->customFields['access_token']);
        $this->assertSame('waiting_device_data', $order->customFields['payment_status']);
    }

    public function testUnmappedEnrollmentStatusFailsAndReportsAnUnexpectedValue(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $reported = [];
        $handler = Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing();
        $handler->shouldReceive('report')->andReturnUsing(function (Throwable $e) use (&$reported): void {
            $reported[] = $e;
        });
        $this->app->instance(ExceptionHandler::class, $handler);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthWithInvalidEci(),
            'id' => 'enroll-5',
            'status' => 'SOMETHING_NEW',
        ]);
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame(OrderStatusEnum::FAILED->value, $this->orderUpdates['status']);
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(UnexpectedValueException::class, $reported[0]);
        $this->assertStringContainsString('SOMETHING_NEW', $reported[0]->getMessage());
    }

    public function testChallengeRequiredPersistsEnrollmentPaymentResponseAndAuthorizationData(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $response = $this->challengeEnrollmentWithAuthTransaction('auth-tx-1');
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($response);

        $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame('enroll-1', $payment->metadata['enrollment_data']['id']);
        $this->assertSame(json_encode($response), $order->customFields['payment_response']);
        $authorizationData = json_decode($order->customFields['authorization_data'], true);
        $this->assertSame('https://acs.test/challenge', $authorizationData['acsUrl']);
        $this->assertSame('auth-tx-1', $authorizationData['authenticationTransactionId']);
        $this->assertSame(OrderStatusEnum::PENDING->value, $this->orderUpdates['status']);
    }

    public function testPayerAuthenticationFailureFailsTheOrderAndStoresThePaymentResponse(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->attachOrder($payment, $order);
        $response = [
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'enroll-3',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
        ];
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($response);

        $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame(OrderStatusEnum::FAILED->value, $this->orderUpdates['status']);
        $this->assertSame(json_encode($response), $order->customFields['payment_response']);
    }

    public function testASecondConcurrentFinalizeSeesProcessingAndDoesNotCallTheGatewayAgain(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $statusDuringCall = null;
        $concurrent = null;
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturnUsing(function () use ($payment, $order, &$statusDuringCall, &$concurrent): array {
            $statusDuringCall = $payment->status;
            $concurrent = $this->processor->finalizeChallenge($payment, $order);

            return $this->challengeEnrollmentWithAuthTransaction('auth-tx-1');
        });
        $this->service->shouldNotReceive('authorizePayment');

        $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $statusDuringCall);
        $this->assertFalse($concurrent->success);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $concurrent->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
    }

    public function testThePreviousStatusIsRestoredWhenTheStepBlowsUp(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $payment->shouldReceive('addMetadata')->andThrow(new RuntimeException('redis down'));
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->challengeEnrollmentWithAuthTransaction('auth-tx-1'));

        try {
            $this->processor->finalizeChallenge($payment, $order);
            $this->fail('Expected the persistence failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('redis down', $e->getMessage());
        }

        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $payment->status);
    }

    public function testAThrowAfterTheAuthorizeSucceededDoesNotRestoreThePreviousStatus(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $payment->shouldReceive('addLog')->with('payment_authorized', Mockery::any())->andThrow(new RuntimeException('log down'));
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-1',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['transactionId' => 'tx-1'],
        ]);

        try {
            $this->processor->finalizeChallenge($payment, $order);
            $this->fail('Expected the log failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('log down', $e->getMessage());
        }

        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testAThrowAfterAFailedMarkKeepsThePaymentFailed(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $payment->shouldReceive('addLog')->with('3ds_authorize_declined', Mockery::any())->andThrow(new RuntimeException('log down'));
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andReturn(['id' => 'intent-1', 'status' => 'DECLINED']);

        try {
            $this->processor->finalizeChallenge($payment, $order);
            $this->fail('Expected the log failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('log down', $e->getMessage());
        }

        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
    }

    public function testLosingTheProcessingRaceMakesNoGatewayCall(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $payment->shouldReceive('transitionStatus')->once()->andReturn(false);
        $this->service->shouldNotReceive('checkPayerEnrollment');
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $result->status);
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $payment->status);
    }

    public function testFinalizeOnAnAlreadyAuthorizedPaymentReturnsItWithoutAuthorizingAgain(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $payment->payment_intent_id = 'intent-1';
        $order = $this->buildOrderMock();
        $this->service->shouldNotReceive('checkPayerEnrollment');
        $this->service->shouldNotReceive('validatePayerAuthResult');
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $result->status);
        $this->assertSame('intent-1', $result->data['intent_id']);
        $this->assertSame('tx-1', $result->data['transaction_id']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testInvalidEciWithPendingAuthenticationAfterChallengeIsNotFinal(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'enroll-1']);
        $this->service->shouldReceive('validatePayerAuthResult')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthWithInvalidEci(),
            'id' => 'validate-3',
            'status' => EchoPayStatusEnum::PENDING_AUTHENTICATION->value,
        ]);
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame(OrderStatusEnum::PENDING->value, $this->orderUpdates['status']);
    }

    public function testInvalidEciWithFailedAuthenticationAfterChallengeIsFinal(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'enroll-1']);
        $this->attachOrder($payment, $order);
        $this->service->shouldReceive('validatePayerAuthResult')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthWithInvalidEci(),
            'id' => 'validate-4',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
        ]);
        $this->service->shouldNotReceive('authorizePayment');

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame(OrderStatusEnum::FAILED->value, $this->orderUpdates['status']);
    }

    public function testAuthorizeWritesTheOrderFieldsDownstreamActionsRead(): void
    {
        [$payment, $order] = $this->authorizeSuccessfully();

        $this->assertSame('intentId:intent-1', $order->customFields['payment_intent_id']);
        $this->assertSame('tx-1', $order->customFields['payment_transaction_id']);
        $this->assertSame(1, $order->customFields['should_capture']);
        $this->assertSame('intent-1', $payment->payment_intent_id);
    }

    public function testAuthorizeStoresTheFullResponseWhereTheReceiptReadsIt(): void
    {
        [$payment] = $this->authorizeSuccessfully();

        $this->assertSame('tx-1', $payment->metadata['data']['payment_response']['processorInformation']['transactionId']);
        $this->assertSame('intent-1', $payment->metadata['data']['payment_response']['id']);
        $this->assertArrayNotHasKey('echo_pay_authorize_response', $payment->metadata['data']);
    }

    public function testAuthorizeEmitsTheLogEventsTheStalePaymentCleanupWhitelists(): void
    {
        $this->authorizeSuccessfully();

        $this->assertSame('intent-1', $this->loggedEvent('payment_authorized')['context']['intent_id']);
        $this->assertSame(100.0, $this->loggedEvent('payment_processing')['context']['amount']);
        $this->assertNotContains('3ds_authorize_success', array_column($this->loggedEvents, 'event'));
    }

    public function testStartChallengeLogsPaymentInitiated(): void
    {
        $this->service->shouldReceive('setupPayer')->once()->andReturn(['id' => 'setup-1', 'consumerAuthenticationInformation' => ['referenceId' => 'ref-1']]);

        $this->processor->startChallenge($this->buildPaymentMock(PaymentStatusEnum::PENDING->value), $this->buildOrderMock());

        $this->assertSame(100, $this->loggedEvent('payment_initiated')['context']['order_id']);
    }

    public function testDeclineExceptionOnAuthorizeFailsPaymentOrderAndCancelsFulfillment(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andThrow($this->decline());

        $this->processor->finalizeChallenge($payment, $order);

        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame(OrderStatusEnum::FAILED->value, $this->orderUpdates['status']);
        $this->assertSame(OrderFulfillmentStatusEnum::CANCELLED->value, $this->orderUpdates['fulfillment_status']);
        $this->assertArrayNotHasKey('should_capture', $order->customFields);
    }

    public function testNonAuthorizedGatewayStatusFailsThePaymentAndLeavesTheOrderUntouched(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andReturn(['id' => 'intent-1', 'status' => 'DECLINED']);

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame([], $this->orderUpdates);
        $this->assertArrayNotHasKey('payment_intent_id', $order->customFields);
    }

    public function testCaptureAndReverseSendThePaymentIntentIdTheLegacySends(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $payment->payment_intent_id = 'intent-1';
        $sent = [];
        $record = function (PaymentCaptureInput $input) use (&$sent): bool {
            $sent[] = $input->transactionId;

            return true;
        };
        $this->service->shouldReceive('capturePayment')->once()->withArgs($record)->andReturn(['id' => 'capture-1']);
        $this->service->shouldReceive('reversePayment')->once()->withArgs($record)->andReturn(['id' => 'reverse-1']);

        $this->processor->capture($payment, $this->buildOrderMock());
        $payment->status = PaymentStatusEnum::AUTHORIZED->value;
        $this->processor->void($payment, $this->buildOrderMock());

        $this->assertSame(['intent-1', 'intent-1'], $sent);
    }

    public function testCaptureFallsBackToTheStoredTransactionIdWithoutAnIntentId(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $sent = null;
        $this->service->shouldReceive('capturePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$sent): bool {
            $sent = $input->transactionId;

            return true;
        })->andReturn(['id' => 'capture-1']);

        $this->processor->capture($payment, $this->buildOrderMock());

        $this->assertSame('tx-1', $sent);
    }

    public function testCaptureTimeoutIsAssumedCapturedWithTheBackupMetadata(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->expectReports(1);
        $this->service->shouldReceive('capturePayment')->once()->andThrow(
            new ConnectException('cURL error 28: timeout', new Request('POST', 'https://echopay.test'))
        );

        $result = $this->processor->capture($payment, $this->buildOrderMock());

        $this->assertTrue($result->success);
        $this->assertSame(['backup_capture' => true], $result->raw);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertTrue($payment->metadata['data']['backup_capture']);
        $this->assertSame('cURL error 28: timeout', $payment->metadata['data']['capture_timeout']['error']);
        $this->assertArrayHasKey('timestamp', $payment->metadata['data']['capture_timeout']);
        $timeoutLog = $this->loggedEvent('payment_capture_timeout');
        $this->assertTrue($timeoutLog['context']['assumed_captured']);
        $this->assertSame('intent-1', $timeoutLog['context']['transaction_id']);
        $this->assertNull($this->findLog('payment_error'));
    }

    public function testCaptureSuccessStoresCaptureDataAndLogsTheLegacyEvents(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldReceive('capturePayment')->once()->andReturn(['id' => 'capture-1', 'status' => 'PENDING']);

        $this->processor->capture($payment, $this->buildOrderMock());

        $this->assertSame(['id' => 'capture-1', 'status' => 'PENDING'], $payment->metadata['data']['capture_data']);
        $this->assertArrayNotHasKey('backup_capture', $payment->metadata['data']);
        $sending = $this->loggedEvent('payment_capture_sending');
        $this->assertSame('intent-1', $sending['context']['transaction_id']);
        $this->assertSame(100.0, $sending['context']['amount']);
        $captured = $this->loggedEvent('payment_captured');
        $this->assertSame('intent-1', $captured['context']['transaction_id']);
        $this->assertSame(100, $captured['context']['order_id']);
    }

    public function testCaptureRejectsAPartialAmountWithoutCallingTheGateway(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldNotReceive('capturePayment');

        $result = $this->processor->capture($payment, $this->buildOrderMock(), amount: 40.0);

        $this->assertFalse($result->success);
        $this->assertSame('Partial amounts are not supported by EchoPay.', $result->message);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testRefundRejectsAPartialAmountWithoutCallingTheGateway(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $this->service->shouldNotReceive('reversePayment');

        $result = $this->processor->refund($payment, $this->buildOrderMock(), amount: 10.0);

        $this->assertFalse($result->success);
        $this->assertSame('Partial amounts are not supported by EchoPay.', $result->message);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testRefundOfAnAuthorizedPaymentReversesItLikeTheLegacy(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $this->service->shouldReceive('reversePayment')->once()->andReturn(['id' => 'reverse-1']);

        $result = $this->processor->refund($payment, $this->buildOrderMock(), amount: 100.0);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testReversalStoresReverseDataAndLogsPaymentReversed(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldReceive('reversePayment')->once()->andReturn(['id' => 'reverse-1', 'status' => 'REVERSED']);

        $this->processor->refund($payment, $this->buildOrderMock(), context: ['reason' => 'customer-requested']);

        $this->assertSame(['id' => 'reverse-1', 'status' => 'REVERSED'], $payment->metadata['data']['reverse_data']);
        $log = $this->loggedEvent('payment_reversed');
        $this->assertSame('intent-1', $log['context']['transaction_id']);
        $this->assertSame('customer-requested', $log['context']['reason']);
        $this->assertSame(100.0, $log['context']['amount']);
    }

    public function testReversalReasonDefaultsToTheLegacyManualReversal(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $reasons = [];
        $this->service->shouldReceive('reversePayment')->twice()->withArgs(function (PaymentCaptureInput $input, MerchantDetail $merchant, string $reason) use (&$reasons): bool {
            $reasons[] = $reason;

            return true;
        })->andReturn(['id' => 'reverse-1']);

        $this->processor->void($payment, $this->buildOrderMock());
        $payment->status = PaymentStatusEnum::PAID->value;
        $this->processor->refund($payment, $this->buildOrderMock(), context: ['reason' => '  ']);

        $this->assertSame(['Manual reversal', 'Manual reversal'], $reasons);
    }

    public function testFailedVoidLeavesTheAuthorizedPaymentUntouchedAndLogsTheError(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldReceive('reversePayment')->once()->andThrow($this->decline());

        $result = $this->processor->void($payment, $this->buildOrderMock(), ['reason' => 'abandoned']);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertArrayNotHasKey('reversal_pending', $payment->metadata);
        $this->assertSame('reverse_payment', $this->loggedEvent('payment_error')['context']['context']);
    }

    public function testFailedRefundOfAPaidPaymentStaysPaid(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldReceive('reversePayment')->once()->andThrow($this->decline());

        $result = $this->processor->refund($payment, $this->buildOrderMock());

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testAuthorizingThroughANonQuietSaveReachesTheAuthorizedWorkflowObserver(): void
    {
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1', 'auth_transaction_id' => 'auth-1']);
        $order->setRelation('app', $this->currentApp);
        $order->setRelation('company', null);
        $firedEvents = [];
        $order->shouldReceive('hasWorkflow')->andReturn(true);
        $order->shouldReceive('fireWorkflow')->andReturnUsing(function (string $event, bool $async, array $params) use (&$firedEvents): void {
            $firedEvents[] = $event;
        });
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $this->attachOrder($payment, $order);
        $payment->shouldNotReceive('saveQuietly');
        $payment->shouldNotReceive('updateQuietly');
        $payment->shouldReceive('save')->andReturnUsing(function () use ($payment) {
            new PaymentObserver()->updated($payment);

            return true;
        });
        $this->service->shouldReceive('validatePayerAuthResult')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'auth-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
        ]);
        $this->service->shouldReceive('authorizePayment')->once()->andReturn([
            'status' => 'AUTHORIZED',
            'id' => 'intent-1',
            'processorInformation' => ['transactionId' => 'tx-1'],
        ]);

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame([WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value], $firedEvents);
    }

    public function testARealClientLetsAConnectExceptionReachTheProcessorSoCaptureIsAssumed(): void
    {
        $guzzle = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new ConnectException('cURL error 28: timeout', new Request('POST', 'https://echopay.test')),
            ])),
        ]);
        $clientReflection = new ReflectionClass(EchoPayClient::class);
        $realClient = $clientReflection->newInstanceWithoutConstructor();
        $clientReflection->getProperty('client')->setValue($realClient, $guzzle);

        $serviceReflection = new ReflectionClass(EchoPayService::class);
        $realService = $serviceReflection->newInstanceWithoutConstructor();
        $serviceReflection->getProperty('client')->setValue($realService, $realClient);
        $serviceReflection->getProperty('app')->setValue($realService, $this->currentApp);

        $processor = new PortalProcessor(
            app: $this->currentApp,
            company: $this->currentCompany,
            service: $realService,
        );
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->expectReports(1);

        $result = $processor->capture($payment, $this->buildOrderMock());

        $this->assertTrue($result->success);
        $this->assertSame(['backup_capture' => true], $result->raw);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertNotNull($this->findLog('payment_capture_timeout'));
        $this->assertNull($this->findLog('payment_error'));
    }

    #[DataProvider('amountBoundaryProvider')]
    public function testCaptureAmountBoundaryAgainstTheOrderTotal(float $amount, bool $accepted): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldReceive('capturePayment')->times($accepted ? 1 : 0)->andReturn(['id' => 'capture-1']);

        $result = $this->processor->capture($payment, $this->buildOrderMock(), amount: $amount);

        $this->assertSame($accepted, $result->success);
    }

    #[DataProvider('amountBoundaryProvider')]
    public function testRefundAmountBoundaryAgainstTheOrderTotal(float $amount, bool $accepted): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldReceive('reversePayment')->times($accepted ? 1 : 0)->andReturn(['id' => 'reverse-1']);

        $result = $this->processor->refund($payment, $this->buildOrderMock(), amount: $amount);

        $this->assertSame($accepted, $result->success);
        $this->assertSame(
            $accepted ? PaymentStatusEnum::REVERSED->value : PaymentStatusEnum::PAID->value,
            $payment->status
        );
    }

    public static function amountBoundaryProvider(): array
    {
        return [
            'within half a cent' => [100.004, true],
            'just over half a cent' => [100.01, false],
            'exact total' => [100.0, true],
        ];
    }

    public function testCaptureOnAnAlreadyPaidPaymentMakesNoGatewayCall(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldNotReceive('capturePayment');

        $result = $this->processor->capture($payment, $this->buildOrderMock());

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testVoidOnAnAlreadyCancelledPaymentMakesNoGatewayCall(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::CANCELLED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldNotReceive('reversePayment');

        $result = $this->processor->void($payment, $this->buildOrderMock());

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
    }

    public function testRefundOnAnAlreadyReversedPaymentMakesNoGatewayCall(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::REVERSED->value);
        $payment->payment_intent_id = 'intent-1';
        $this->service->shouldNotReceive('reversePayment');

        $result = $this->processor->refund($payment, $this->buildOrderMock());

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testCaptureWithNeitherIdFailsWithoutCallingTheGateway(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value);
        $this->service->shouldNotReceive('capturePayment');

        $result = $this->processor->capture($payment, $this->buildOrderMock());

        $this->assertFalse($result->success);
    }

    private function authorizeSuccessfully(): array
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $payment->payment_intent_id = 'setup-1';
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-1',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['transactionId' => 'tx-1'],
        ]);

        $this->processor->finalizeChallenge($payment, $order);

        return [$payment, $order];
    }

    private function challengeEnrollmentWithAuthTransaction(string $authenticationTransactionId): array
    {
        return [
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'acsUrl' => 'https://acs.test/challenge',
                'pareq' => 'pareq-payload',
                'accessToken' => 'tok-1',
                'stepUpUrl' => 'https://acs.test/step-up',
                'authenticationTransactionId' => $authenticationTransactionId,
            ]),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::PENDING_AUTHENTICATION->value,
        ];
    }

    private function consumerAuthWithInvalidEci(): ConsumerAuthentication
    {
        return ConsumerAuthentication::from([
            ...$this->consumerAuthBase(),
            'eci' => '07',
            'eciRaw' => '07',
        ]);
    }

    private function decline(): EchoPayException
    {
        return new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => [
                'errorInformation' => ['reason' => 'INSUFFICIENT_FUND', 'message' => 'Decline - Insufficient funds in the account.'],
                'processorInformation' => ['responseCode' => '51'],
                'paymentInsightsInformation' => ['responseInsights' => ['category' => 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME']],
            ]]
        );
    }

    private function declineFailure(): PaymentFailure
    {
        return new PaymentFailure(
            code: 'INSUFFICIENT_FUND',
            message: 'Decline - Insufficient funds in the account.',
            processorResponseCode: '51',
            responseInsight: 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME',
        );
    }

    private function guzzleFailure(): RequestException
    {
        return new RequestException(
            'Client error: 400',
            new Request('POST', 'https://echopay.test'),
            new Response(400, [], json_encode(['message' => 'Invalid card number', 'data' => ['field' => 'pan']]))
        );
    }

    private function frictionlessEnrollment(): array
    {
        return [
            'consumerAuthenticationInformation' => $this->validConsumerAuth(),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
            'paymentInformation' => ['card' => ['type' => 'VISA']],
        ];
    }

    private function expectReports(int $times): void
    {
        $handler = Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing();
        $handler->shouldReceive('report')->times($times);
        $this->app->instance(ExceptionHandler::class, $handler);
    }

    private function attachOrder(Payments $payment, Order $order): void
    {
        $payment->shouldReceive('getAttribute')->with('payable')->andReturn($order);
        $payment->shouldReceive('getRelationValue')->with('payable')->andReturn($order);
        $order->shouldReceive('updateQuietly')->andReturnUsing(function (array $attributes): bool {
            $this->orderUpdates = $attributes;

            return true;
        });
    }

    private function findLog(string $event): ?array
    {
        foreach ($this->loggedEvents as $log) {
            if ($log['event'] === $event) {
                return $log;
            }
        }

        return null;
    }

    private function loggedEvent(string $event): array
    {
        $matches = array_values(array_filter($this->loggedEvents, fn (array $log) => $log['event'] === $event));

        $this->assertCount(1, $matches, "Expected exactly one '{$event}' log.");

        return $matches[0];
    }

    public function testDefaultMerchantIsUsedOnSetup(): void
    {
        $merchant = null;
        $this->service->shouldReceive('setupPayer')->once()->withArgs(function (string $orderId, string $card, MerchantDetail $received) use (&$merchant): bool {
            $merchant = $received;

            return true;
        })->andReturn(['id' => 'setup-1', 'consumerAuthenticationInformation' => ['referenceId' => 'ref-1']]);

        $this->processor->startChallenge($this->buildPaymentMock(PaymentStatusEnum::PENDING->value), $this->buildOrderMock());

        $this->assertSame(['merchant-default', 'key-default', 'secret-default'], [$merchant->id, $merchant->key, $merchant->secretKey]);
    }

    public function testMultiMerchantRoutesEveryCallToTheOrderTypeCredentials(): void
    {
        $this->enableMultiMerchant();
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->setOrderType($order, 'PARKING');
        $seen = [];
        $record = function (string $step, MerchantDetail $merchant) use (&$seen): bool {
            $seen[$step] = [$merchant->id, $merchant->key, $merchant->secretKey];

            return true;
        };

        $this->service->shouldReceive('setupPayer')->once()
            ->withArgs(fn (string $o, string $c, MerchantDetail $m): bool => $record('setup', $m))
            ->andReturn(['id' => 'setup-1', 'consumerAuthenticationInformation' => ['referenceId' => 'ref-1']]);
        $this->service->shouldReceive('checkPayerEnrollment')->once()
            ->withArgs(fn (PaymentDetail $d, MerchantDetail $m): bool => $record('enrollment', $m))
            ->andReturn($this->challengeEnrollment());
        $this->service->shouldReceive('validatePayerAuthResult')->once()
            ->withArgs(fn (string $t, PaymentDetail $d, MerchantDetail $m): bool => $record('validate', $m))
            ->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()
            ->withArgs(fn (PaymentDetail $d, ConsumerAuthentication $c, MerchantDetail $m): bool => $record('authorize', $m))
            ->andReturn(['id' => 'intent-1', 'status' => 'AUTHORIZED', 'processorInformation' => ['transactionId' => 'tx-1']]);
        $this->service->shouldReceive('capturePayment')->once()
            ->withArgs(fn (PaymentCaptureInput $i, MerchantDetail $m): bool => $record('capture', $m))
            ->andReturn(['id' => 'capture-1']);
        $this->service->shouldReceive('reversePayment')->once()
            ->withArgs(fn (PaymentCaptureInput $i, MerchantDetail $m, string $r): bool => $record('reverse', $m))
            ->andReturn(['id' => 'reverse-1']);

        $authorized = ['data' => ['echo_pay_authorize_transaction_id' => 'tx-1']];
        $order->set('auth_transaction_id', 'enroll-1');
        $this->processor->startChallenge($this->buildPaymentMock(PaymentStatusEnum::PENDING->value), $order);
        $this->processor->finalizeChallenge($this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value), $order);
        $this->processor->finalizeChallenge(
            $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value),
            $order
        );
        $this->processor->capture($this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: $authorized), $order);
        $this->processor->void($this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: $authorized), $order);

        $this->assertSame(['setup', 'enrollment', 'validate', 'authorize', 'capture', 'reverse'], array_keys($seen));

        foreach ($seen as $step => $credentials) {
            $this->assertSame(['parking-id', 'parking-key', 'parking-secret'], $credentials, $step);
        }
    }

    public function testMultiMerchantWithoutOrderTypeNameFallsBackToDefaultCredentials(): void
    {
        $this->enableMultiMerchant();
        $merchant = null;

        $this->service->shouldReceive('setupPayer')->once()->withArgs(function (string $orderId, string $card, MerchantDetail $received) use (&$merchant): bool {
            $merchant = $received;

            return true;
        })->andReturn(['id' => 'setup-1', 'consumerAuthenticationInformation' => ['referenceId' => 'ref-1']]);

        $this->processor->startChallenge($this->buildPaymentMock(PaymentStatusEnum::PENDING->value), $this->buildOrderMock());

        $this->assertSame('merchant-default', $merchant->id);
    }

    public function testMissingDefaultCredentialsFailWithDescriptiveMessage(): void
    {
        $this->appSettings = [];
        $this->service->shouldNotReceive('setupPayer');

        $result = $this->processor->startChallenge($this->buildPaymentMock(PaymentStatusEnum::PENDING->value), $this->buildOrderMock());

        $this->assertFalse($result->success);
        $this->assertSame(
            'Missing default merchant credentials. Please configure ECHO_PAY_MERCHANT_ID, ECHO_PAY_MERCHANT_KEY, and ECHO_PAY_MERCHANT_SECRET.',
            $result->message
        );
    }

    public function testMissingOrderTypeCredentialsFailWithDescriptiveMessage(): void
    {
        $this->appSettings = ['portal_multy_merchant' => 1, 'ECHO_PAY_MERCHANT_ID' => 'merchant-default'];
        $order = $this->buildOrderMock();
        $this->setOrderType($order, 'PARKING');
        $payment = $this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: [
            'data' => ['echo_pay_authorize_transaction_id' => 'tx-1'],
        ]);
        $this->service->shouldNotReceive('capturePayment');

        $result = $this->processor->capture($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(
            "Missing merchant credentials for order type 'PARKING'. Please configure PARKING_ECHO_PAY_MERCHANT_ID, PARKING_ECHO_PAY_MERCHANT_KEY, and PARKING_ECHO_PAY_MERCHANT_SECRET.",
            $result->message
        );
    }

    public function testAuthorizeFingerprintUsesTheRoutedMerchantId(): void
    {
        $this->enableMultiMerchant();
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $this->setOrderType($order, 'PARKING');
        $detail = null;
        $merchant = null;
        $this->service->shouldReceive('checkPayerEnrollment')->once()->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->withArgs(function (PaymentDetail $d, ConsumerAuthentication $c, MerchantDetail $m) use (&$detail, &$merchant): bool {
            $detail = $d;
            $merchant = $m;

            return true;
        })->andReturn(['id' => 'intent-1', 'status' => 'AUTHORIZED', 'processorInformation' => ['transactionId' => 'tx-1']]);

        $result = $this->processor->finalizeChallenge($this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value), $order);

        $this->assertTrue($result->success);
        $this->assertSame('parking-id' . $order->id, $detail->deviceInformation->fingerprintSessionId);
        $this->assertSame('parking-id', $merchant->merchantDefinedInformation->cardIdentifier);
    }

    public function testCurrencyIsAlwaysDop(): void
    {
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $order->currency = 'USD';
        $currencies = [];
        $this->service->shouldReceive('checkPayerEnrollment')->once()->withArgs(function (PaymentDetail $detail) use (&$currencies): bool {
            $currencies['enrollment'] = $detail->orderInformation->currency;

            return true;
        })->andReturn($this->frictionlessEnrollment());
        $this->service->shouldReceive('authorizePayment')->once()->withArgs(function (PaymentDetail $detail) use (&$currencies): bool {
            $currencies['authorize'] = $detail->orderInformation->currency;

            return true;
        })->andReturn(['id' => 'intent-1', 'status' => 'AUTHORIZED', 'processorInformation' => ['transactionId' => 'tx-1']]);
        $this->service->shouldReceive('capturePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$currencies): bool {
            $currencies['capture'] = $input->currency;

            return true;
        })->andReturn(['id' => 'capture-1']);
        $this->service->shouldReceive('reversePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$currencies): bool {
            $currencies['reverse'] = $input->currency;

            return true;
        })->andReturn(['id' => 'reverse-1']);
        $authorized = ['data' => ['echo_pay_authorize_transaction_id' => 'tx-1']];

        $this->processor->finalizeChallenge($this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value), $order);
        $this->processor->capture($this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: $authorized), $order);
        $this->processor->void($this->buildPaymentMock(PaymentStatusEnum::AUTHORIZED->value, metadata: $authorized), $order);

        $this->assertSame(
            ['enrollment' => 'DOP', 'authorize' => 'DOP', 'capture' => 'DOP', 'reverse' => 'DOP'],
            $currencies
        );
    }

    public function testBillingCoercesMissingMetadataToEmptyStrings(): void
    {
        $order = $this->buildOrderMock(['auth_session_id' => 'ref-1']);
        $billTo = null;
        $this->service->shouldReceive('checkPayerEnrollment')->once()->withArgs(function (PaymentDetail $detail) use (&$billTo): bool {
            $billTo = $detail->orderInformation->billTo;

            return true;
        })->andReturn($this->challengeEnrollment());

        $result = $this->processor->finalizeChallenge($this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value), $order);

        $this->assertTrue($result->success);
        $this->assertSame('', $billTo->country);
        $this->assertSame('', $billTo->postalCode);
        $this->assertSame('', $billTo->phone);
        $this->assertSame('Mock', $billTo->firstName);
        $this->assertSame('mock@example.com', $billTo->email);
    }

    private function enableMultiMerchant(): void
    {
        $this->appSettings = [
            ...$this->appSettings,
            'portal_multy_merchant' => 1,
            'PARKING_ECHO_PAY_MERCHANT_ID' => 'parking-id',
            'PARKING_ECHO_PAY_MERCHANT_KEY' => 'parking-key',
            'PARKING_ECHO_PAY_MERCHANT_SECRET' => 'parking-secret',
        ];
    }

    private function setOrderType(Order $order, string $name): void
    {
        $orderType = new OrderTypes();
        $orderType->name = $name;
        $order->setRelation('orderType', $orderType);
    }

    private function challengeEnrollment(): array
    {
        return [
            'consumerAuthenticationInformation' => ConsumerAuthentication::from([
                ...$this->consumerAuthBase(),
                'acsUrl' => 'https://acs.test/challenge',
                'pareq' => 'pareq-payload',
                'accessToken' => 'tok-1',
                'stepUpUrl' => 'https://acs.test/step-up',
            ]),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::PENDING_AUTHENTICATION->value,
        ];
    }

    private function buildPaymentMock(
        string $initialStatus,
        ?PaymentMethods $paymentMethod = null,
        array $metadata = [],
    ): Payments {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->exists = true;
        $payment->id = 1;
        $payment->status = $initialStatus;
        $payment->metadata = $metadata;
        $payment->payment_intent_id = null;

        $paymentMethod ??= $this->buildPaymentMethodMock();
        $user = $this->buildUserMock();

        $payment->shouldReceive('getAttribute')->with('paymentMethod')->andReturn($paymentMethod)->byDefault();
        $payment->shouldReceive('getRelationValue')->with('paymentMethod')->andReturn($paymentMethod)->byDefault();
        $payment->shouldReceive('getAttribute')->with('user')->andReturn($user)->byDefault();
        $payment->shouldReceive('getRelationValue')->with('user')->andReturn($user)->byDefault();

        $payment->shouldReceive('transitionStatus')->andReturnUsing(function (string $from, string $to) use ($payment): bool {
            if ($payment->status !== $from) {
                return false;
            }

            $payment->status = $to;

            return true;
        })->byDefault();

        $payment->shouldReceive('save')->andReturnSelf()->byDefault();
        $payment->shouldReceive('saveQuietly')->andReturnSelf()->byDefault();
        $payment->shouldReceive('update')->andReturnUsing(function (array $attrs) use ($payment) {
            foreach ($attrs as $k => $v) {
                $payment->{$k} = $v;
            }

            return true;
        })->byDefault();

        $payment->shouldReceive('addMetadata')->andReturnUsing(function (array $data) use ($payment) {
            $existing = $payment->metadata ?? [];
            $payment->metadata = [
                ...$existing,
                ...$data,
                'data' => [
                    ...($existing['data'] ?? []),
                    ...($data['data'] ?? []),
                ],
            ];
        })->byDefault();

        $payment->shouldReceive('getMetadata')->andReturnUsing(function (string $key) use ($payment) {
            return $payment->metadata['data'][$key] ?? null;
        })->byDefault();

        $payment->shouldReceive('addLog')->andReturnUsing(function (string $event, array $context = [], ?PaymentFailure $failure = null): void {
            $this->loggedEvents[] = ['event' => $event, 'context' => $context, 'failure' => $failure];
        })->byDefault();

        $payment->shouldReceive('markAsPaid')->andReturnUsing(function (array $metadata = []) use ($payment) {
            $payment->status = PaymentStatusEnum::PAID->value;
            $existing = $payment->metadata ?? [];
            $payment->metadata = [
                ...$existing,
                ...$metadata,
                'data' => [
                    ...($existing['data'] ?? []),
                    ...($metadata['data'] ?? []),
                ],
            ];
        })->byDefault();

        return $payment;
    }

    private function buildPaymentMethodMock(string $stripeCardId = 'mock-pid-123'): PaymentMethods
    {
        $pm = Mockery::mock(PaymentMethods::class)->makePartial();
        $pm->exists = true;
        $pm->id = 1;
        $pm->stripe_card_id = $stripeCardId;
        $pm->metadata = [];

        $pm->shouldReceive('getMetadata')->andReturnUsing(function (string $key) use ($pm) {
            return $pm->metadata[$key] ?? null;
        })->byDefault();

        $pm->shouldReceive('save')->andReturnSelf()->byDefault();

        return $pm;
    }

    private function buildUserMock(): Users
    {
        $user = Mockery::mock(Users::class)->makePartial();
        $user->exists = true;
        $user->id = 1;
        $user->email = 'mock@example.com';
        $user->firstname = 'Mock';
        $user->lastname = 'User';

        $user->shouldReceive('get')->andReturnNull()->byDefault();

        return $user;
    }

    private function buildOrderMock(array $customFields = []): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 100;
        $order->order_number = 100;
        $order->currency = 'DOP';
        $order->customFields = $customFields;
        $order->metadata = ['data' => ['user_ip' => '127.0.0.1']];

        $order->shouldReceive('getTotalAmount')->andReturn(100.00)->byDefault();
        $order->shouldReceive('getTotalTaxAmount')->andReturn(0.0)->byDefault();

        $order->shouldReceive('updateQuietly')->andReturnUsing(function (array $attributes): bool {
            $this->orderUpdates = $attributes;

            return true;
        })->byDefault();

        $order->shouldReceive('get')->andReturnUsing(function (string $key) use ($order) {
            return $order->customFields[$key] ?? null;
        })->byDefault();

        $order->shouldReceive('set')->andReturnUsing(function (string $key, $value) use ($order) {
            $order->customFields[$key] = $value;

            // Real Order::set() returns AppsCustomFields; satisfy the typed return.
            return Mockery::mock(AppsCustomFields::class);
        })->byDefault();

        return $order;
    }

    private function validConsumerAuth(): ConsumerAuthentication
    {
        return ConsumerAuthentication::from([
            ...$this->consumerAuthBase(),
            'eci' => '05',
            'eciRaw' => '05',
            'indicator' => 'vbv',
            'authenticationResult' => '0',
        ]);
    }

    private function consumerAuthBase(): array
    {
        return [
            'indicator' => null,
            'authenticationTransactionId' => null,
            'eciRaw' => null,
            'authenticationResult' => null,
            'strongAuthentication' => [],
            'authenticationStatusMsg' => null,
            'eci' => null,
            'token' => '',
            'accessToken' => null,
            'cavv' => null,
            'paresStatus' => null,
            'xid' => null,
            'directoryServerTransactionId' => null,
            'threeDSServerTransactionId' => null,
            'specificationVersion' => null,
            'acsTransactionId' => null,
            'ucafCollectionIndicator' => null,
            'ucafAuthenticationData' => null,
            'challengeRequired' => null,
            'acsUrl' => null,
            'acsReferenceNumber' => null,
            'stepUpUrl' => null,
            'pareq' => null,
            'veresEnrolled' => null,
            'acsOperatorID' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use PHPUnit\Framework\Attributes\DataProvider;

class PortalMockPaymentFlowTest extends PortalMockFlowCase
{
    public function testFrictionlessFlowTokenizesStartsFinalizesAndCapturesThroughTheMockClient(): void
    {
        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());
        $this->assertArrayHasKey('id', $paymentMethod);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();

        $start = $this->startChallenge($paymentId);

        $this->assertTrue($start['success'], $start['message']);
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $start['status']);
        $this->assertSame('https://mock.echopay.local/3ds/collect', $start['data']['deviceDataUrl']);
        $this->assertSame('mock-access-token', $start['data']['accessToken']);
        $this->assertSame($start['data']['deviceDataUrl'], $start['data']['device_data_collection_url']);
        $this->assertSame($start['data']['referenceId'], $start['data']['reference_id']);
        $this->assertSame('https://mock.echopay.local', $start['data']['device_data_origin']);
        $this->assertStringContainsString('action="https://mock.echopay.local/3ds/collect"', $start['data']['method_form']);
        $this->assertStringContainsString('name="JWT" value="mock-access-token"', $start['data']['method_form']);
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, Payments::find($paymentId)->status);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertTrue($finalize['success'], $finalize['message']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $finalize['status']);
        $this->assertNotEmpty($finalize['data']['intent_id']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, Payments::find($paymentId)->status);

        $capture = $this->runPaymentAction('capturePayment', $paymentId);

        $this->assertSame('success', $capture['status'], $capture['message']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $capture['payment']['status']);
        $this->assertSame(PaymentStatusEnum::PAID->value, Payments::find($paymentId)->status);
    }

    public function testChallengeRequiredReturnsTheAcsKeysAndFinalizeAuthorizesAfterTheWebhook(): void
    {
        [$paymentId, $orderId] = $this->createPortalPaymentOnFreshOrder();
        $validatedWith = null;
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthentication([
                'acsUrl' => 'https://acs.test/challenge',
                'stepUpUrl' => 'https://acs.test/step-up',
                'pareq' => 'pareq-payload',
                'accessToken' => 'step-token',
                'authenticationTransactionId' => 'auth-tx-1',
            ]),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::PENDING_AUTHENTICATION->value,
        ]);
        $service->shouldReceive('validatePayerAuthResult')->once()->withArgs(function (string $transactionId) use (&$validatedWith): bool {
            $validatedWith = $transactionId;

            return true;
        })->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthentication(['eci' => '05', 'eciRaw' => '05']),
            'id' => 'validate-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
        ]);
        $this->bindPortalProcessor($service);
        $this->startChallenge($paymentId);

        $challenge = $this->finalizeChallenge($paymentId);

        $this->assertTrue($challenge['success'], $challenge['message']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $challenge['status']);
        $this->assertSame('https://acs.test/challenge', $challenge['data']['acsUrl']);
        $this->assertSame('https://acs.test/step-up', $challenge['data']['stepUpUrl']);
        $this->assertSame('pareq-payload', $challenge['data']['pareq']);
        $this->assertSame('step-token', $challenge['data']['accessToken']);
        $this->assertSame('https://test.local/3ds/return', $challenge['data']['returnUrl']);
        $this->assertStringContainsString('action="https://acs.test/step-up"', $challenge['data']['challenge_form']);
        $this->assertStringContainsString('name="JWT" value="step-token"', $challenge['data']['challenge_form']);
        $this->assertStringContainsString('name="MD" value="' . $orderId . '"', $challenge['data']['challenge_form']);
        $this->assertSame('auth-tx-1', Order::find($orderId)->get('auth_transaction_id'));

        Order::find($orderId)->set('auth_transaction_id', 'webhook-tx');
        $authorized = $this->finalizeChallenge($paymentId);

        $this->assertTrue($authorized['success'], $authorized['message']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $authorized['status']);
        $this->assertSame('webhook-tx', $validatedWith);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, Payments::find($paymentId)->status);
    }

    public function testVoidReversesTheAuthorizeTransactionAndCancelsThePayment(): void
    {
        $reversedTransactionId = null;
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('reversePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$reversedTransactionId): bool {
            $reversedTransactionId = $input->transactionId;

            return true;
        })->andReturn(['id' => 'reversal-1', 'status' => 'REVERSED']);
        $paymentId = $this->createAuthorizedPortalPayment($service);

        $void = $this->runPaymentAction('voidPayment', $paymentId);

        $this->assertSame('success', $void['status'], $void['message']);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $void['payment']['status']);
        $payment = Payments::find($paymentId);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
        $this->assertSame($payment->payment_intent_id, $reversedTransactionId);
    }

    public function testRefundReversesAPaidPayment(): void
    {
        $reversedTransactionId = null;
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('reversePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$reversedTransactionId): bool {
            $reversedTransactionId = $input->transactionId;

            return true;
        })->andReturn(['id' => 'refund-1', 'status' => 'REVERSED']);
        $paymentId = $this->createAuthorizedPortalPayment($service);
        $capture = $this->runPaymentAction('capturePayment', $paymentId);
        $this->assertSame(PaymentStatusEnum::PAID->value, $capture['payment']['status'], $capture['message']);

        $refund = $this->runPaymentAction('refundPayment', $paymentId);

        $this->assertSame('success', $refund['status'], $refund['message']);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $refund['payment']['status']);
        $payment = Payments::find($paymentId);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
        $this->assertSame($payment->payment_intent_id, $reversedTransactionId);
    }

    public function testVerifyReturnsTheStoredStatusWithoutCallingTheGateway(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $paymentId = $this->createAuthorizedPortalPayment($service);
        $service->shouldNotReceive('reversePayment');
        $service->shouldNotReceive('capturePayment');
        $service->shouldNotReceive('authorizePayment');
        $service->shouldNotReceive('checkPayerEnrollment');

        $verify = $this->runPaymentAction(
            mutation: 'verifyPayment',
            paymentId: $paymentId,
            selection: 'status message transaction_id',
        );

        $this->assertSame('success', $verify['status']);
        $this->assertStringContainsString('Stored payment status: authorized', $verify['message']);
        $this->assertNotEmpty($verify['transaction_id']);
    }

    public function testProcessPaymentRejectsPortalWithoutMarkingThePaymentFailed(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldNotReceive('authorizePayment');
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();

        $process = $this->runPaymentAction('processPayment', $paymentId);

        $this->assertSame('error', $process['status']);
        $this->assertStringContainsString('requires 3DS', $process['message']);
        $this->assertSame(PaymentStatusEnum::PENDING->value, Payments::find($paymentId)->status);
    }

    public function testEveryGatewayCallUsesTheMerchantCredentialsOfTheApp(): void
    {
        $seen = [];
        $record = function (string $step, MerchantDetail $merchant) use (&$seen): bool {
            $seen[$step] = [$merchant->id, $merchant->key, $merchant->secretKey];

            return true;
        };
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('setupPayer')->once()
            ->withArgs(fn (string $orderId, string $card, MerchantDetail $merchant): bool => $record('setup', $merchant))
            ->passthru();
        $service->shouldReceive('checkPayerEnrollment')->once()
            ->withArgs(fn (PaymentDetail $detail, MerchantDetail $merchant): bool => $record('enrollment', $merchant))
            ->passthru();
        $service->shouldReceive('authorizePayment')->once()
            ->withArgs(fn (PaymentDetail $detail, ConsumerAuthentication $consumer, MerchantDetail $merchant): bool => $record('authorize', $merchant))
            ->passthru();
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $finalize['status'], $finalize['message']);
        $this->assertSame(['setup', 'enrollment', 'authorize'], array_keys($seen));

        foreach ($seen as $step => $credentials) {
            $this->assertSame(['mock-merchant', 'mock-key', 'mock-secret'], $credentials, $step);
        }
    }

    public function testASecondFinalizeOnAPaymentAlreadyProcessingMakesNoGatewayCall(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldNotReceive('checkPayerEnrollment');
        $service->shouldNotReceive('validatePayerAuthResult');
        $service->shouldNotReceive('authorizePayment');
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);
        Payments::query()->whereKey($paymentId)->update(['status' => PaymentStatusEnum::PROCESSING->value]);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $finalize['status']);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, Payments::find($paymentId)->status);
    }

    #[DataProvider('settledPaymentActions')]
    public function testRepeatingAnActionOnASettledPaymentMakesNoGatewayCall(
        string $mutation,
        string $settledStatus
    ): void {
        $service = $this->portalServiceWithRealMockClient();
        $paymentId = $this->createAuthorizedPortalPayment($service);
        Payments::query()->whereKey($paymentId)->update(['status' => $settledStatus]);
        $service->shouldNotReceive('capturePayment');
        $service->shouldNotReceive('reversePayment');

        $response = $this->runPaymentAction($mutation, $paymentId);

        $this->assertSame('error', $response['status'], $response['message']);
        $this->assertSame($settledStatus, Payments::find($paymentId)->status);
    }

    public static function settledPaymentActions(): array
    {
        return [
            'capture on a paid payment' => ['capturePayment', PaymentStatusEnum::PAID->value],
            'void on a cancelled payment' => ['voidPayment', PaymentStatusEnum::CANCELLED->value],
            'refund on a reversed payment' => ['refundPayment', PaymentStatusEnum::REVERSED->value],
        ];
    }

    public function testACaptureTimeoutIsTreatedAsCapturedAndKeepsTheBackupMetadata(): void
    {
        $this->collectReportedExceptions();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('capturePayment')->once()->andThrow(
            new ConnectException('cURL error 28: timed out', new Request('POST', 'https://mock.echopay.local/capture'))
        );
        $paymentId = $this->createAuthorizedPortalPayment($service);

        $capture = $this->runPaymentAction('capturePayment', $paymentId);

        $this->assertSame('success', $capture['status'], $capture['message']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $capture['payment']['status']);
        $payment = Payments::find($paymentId);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertTrue($payment->getMetadata('backup_capture'));
        $this->assertStringContainsString('timed out', $payment->getMetadata('capture_timeout')['error']);
    }
}

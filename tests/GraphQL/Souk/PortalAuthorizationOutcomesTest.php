<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Jobs\RetryPaymentReversalJob;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

class PortalAuthorizationOutcomesTest extends PortalMockFlowCase
{
    protected function tearDown(): void
    {
        $this->apps->set(ConfigurationEnum::BYPASS_ECI->value, 0);

        parent::tearDown();
    }

    #[DataProvider('threeDSOutcomes')]
    public function testEnrollmentOutcomeDecidesWhetherThePaymentIsAuthorized(
        string $brand,
        string $gatewayStatus,
        ?string $paresStatus,
        ?string $eci,
        bool $bypassEci,
        string $expectedStatus,
        ?string $expectedInconclusiveOutcome
    ): void {
        $this->apps->set(ConfigurationEnum::BYPASS_ECI->value, $bypassEci ? 1 : 0);
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthentication([
                'paresStatus' => $paresStatus,
                'veresEnrolled' => 'Y',
                'eci' => $eci,
                'eciRaw' => $eci,
            ]),
            'id' => 'enroll-1',
            'status' => $gatewayStatus,
            'paymentInformation' => ['card' => ['type' => $brand]],
        ]);
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertSame($expectedStatus, $finalize['status'], $finalize['message']);
        $this->assertSame($expectedStatus, Payments::find($paymentId)->status);
        $inconclusive = $this->logsOf($paymentId, '3ds_authentication_inconclusive')->first();
        $this->assertSame($expectedInconclusiveOutcome, $inconclusive?->metadata['outcome']);
    }

    public static function threeDSOutcomes(): array
    {
        $authorized = PaymentStatusEnum::AUTHORIZED->value;
        $pending = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $failed = PaymentStatusEnum::FAILED->value;
        $successful = EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value;
        $authenticationFailed = EchoPayStatusEnum::AUTHENTICATION_FAILED->value;

        return [
            'visa 05 is accepted' => ['VISA', $successful, 'Y', '05', false, $authorized, null],
            'amex 05 is accepted' => ['AMEX', $successful, 'Y', '05', false, $authorized, null],
            'visa 06 is handled as attempted' => ['VISA', $successful, 'Y', '06', false, $pending, 'attempted'],
            'mastercard 02 is accepted' => ['MASTERCARD', $successful, 'Y', '02', false, $authorized, null],
            'mastercard 01 is handled as attempted' => ['MASTERCARD', $successful, 'Y', '01', false, $pending, 'attempted'],
            'visa carrying the mastercard attempted eci is rejected' => ['VISA', $successful, 'Y', '01', false, $pending, null],
            'mastercard with an empty eci is accepted when the bypass is on' => ['MASTERCARD', $successful, 'Y', null, true, $authorized, null],
            'pares attempted is handled as attempted' => ['VISA', $successful, 'A', '06', false, $pending, 'attempted'],
            'pares unavailable is handled as unavailable' => ['VISA', $successful, 'U', '07', false, $pending, 'unavailable'],
            'pares not authenticated without an eci fails' => ['VISA', $successful, 'N', null, false, $failed, null],
            'pares rejected without a valid eci fails' => ['VISA', $successful, 'R', '07', false, $failed, null],
            'authentication failed status fails' => ['VISA', $authenticationFailed, 'N', null, false, $failed, null],
        ];
    }

    public function testAuthorizeDeclineFailsThePaymentLogsTheGatewayFieldsAndShowsTheShopperTheTranslation(): void
    {
        $reported = $this->collectReportedExceptions();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andThrow($this->insufficientFundsDecline());
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $finalize['status']);
        $this->assertSame(__('payment_errors.INSUFFICIENT_FUND', [], 'es'), $finalize['message']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, Payments::find($paymentId)->status);
        $log = $this->logsOf($paymentId, '3ds_authorize_failed')->firstOrFail();
        $this->assertSame('INSUFFICIENT_FUND', $log->error_code);
        $this->assertSame('Decline - Insufficient funds in the account.', $log->error_message);
        $this->assertSame('51', $log->processor_response_code);
        $this->assertSame('ISSUER_CANNOT_APPROVE_AT_THIS_TIME', $log->response_insight);
        $this->assertCount(0, $reported);
    }

    #[DataProvider('gatewayFailureCodes')]
    public function testOnlyGatewayFailuresAreReported(int $statusCode, bool $expectedReported): void
    {
        $reported = $this->collectReportedExceptions();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andThrow(
            new EchoPayException(
                message: "Server error: `POST https://echopay.test/payments` resulted in a `{$statusCode}` response",
                code: $statusCode,
                errorBody: []
            )
        );
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertSame(PaymentStatusEnum::FAILED->value, $finalize['status']);
        $gatewayReports = array_filter(
            $reported->getArrayCopy(),
            fn ($exception): bool => $exception instanceof EchoPayException
        );
        $this->assertSame($expectedReported, $gatewayReports !== []);

        if ($expectedReported) {
            $this->assertSame(__('payment_errors.PROCESSOR_UNAVAILABLE', [], 'es'), $finalize['message']);
            $this->assertStringNotContainsStringIgnoringCase('http', $finalize['message']);
            $this->assertStringNotContainsString('cURL', $finalize['message']);
            $this->assertStringContainsString(
                'echopay.test',
                (string) $this->logsOf($paymentId, '3ds_authorize_failed')->firstOrFail()->error_message
            );
        }
    }

    public function testATimeoutDuringAuthorizeShowsTheShopperTheTimeoutMessageAndKeepsTheRawErrorInTheLog(): void
    {
        $rawError = 'cURL error 28: Operation timed out for https://echopay.test/payments';
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andThrow(
            new ConnectException($rawError, new Request('POST', 'https://echopay.test/payments'))
        );
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $finalize['status']);
        $this->assertSame(__('payment_errors.SERVER_TIMEOUT', [], 'es'), $finalize['message']);
        $this->assertStringNotContainsString('cURL', $finalize['message']);
        $this->assertSame($rawError, $this->logsOf($paymentId, '3ds_authorize_failed')->firstOrFail()->error_message);
    }

    public static function gatewayFailureCodes(): array
    {
        return [
            'decline 400' => [400, false],
            'server error 500' => [500, true],
            'service unavailable 503' => [503, true],
        ];
    }

    #[DataProvider('holdStatuses')]
    public function testAHoldWithoutFullAuthorizationIsReversedAndFailsThePaymentWithItsOwnCode(
        string $gatewayStatus,
        string $expectedErrorCode,
        ?string $authorizedAmount,
        ?float $expectedReversedAmount
    ): void {
        $reversed = [];
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-held',
            'status' => $gatewayStatus,
            'processorInformation' => ['transactionId' => 'tx-held'],
            ...($authorizedAmount === null ? [] : [
                'orderInformation' => ['amountDetails' => ['authorizedAmount' => $authorizedAmount]],
            ]),
        ]);
        $service->shouldReceive('reversePayment')->once()->withArgs(function (PaymentCaptureInput $input) use (&$reversed): bool {
            $reversed = ['transactionId' => $input->transactionId, 'amount' => (float) $input->totalAmount];

            return true;
        })->andReturn(['id' => 'reverse-1']);
        $this->bindPortalProcessor($service);
        [$paymentId, $orderId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $finalize['status']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, Payments::find($paymentId)->status);
        $this->assertSame('intent-held', $reversed['transactionId']);
        $this->assertEqualsWithDelta(
            $expectedReversedAmount ?? Order::find($orderId)->getTotalAmount(),
            $reversed['amount'],
            0.001
        );
        $log = $this->logsOf($paymentId, '3ds_authorize_declined')->firstOrFail();
        $this->assertSame($expectedErrorCode, $log->error_code);
        $this->assertTrue($log->metadata['hold_reversed']);
    }

    public static function holdStatuses(): array
    {
        return [
            'partial authorization reverses the authorized amount' => ['PARTIAL_AUTHORIZED', 'PARTIAL_AUTHORIZATION', '40.00', 40.0],
            'pending review reverses the order total' => ['AUTHORIZED_PENDING_REVIEW', 'PENDING_REVIEW', null, null],
            'risk declined reverses the order total' => ['AUTHORIZED_RISK_DECLINED', 'RISK_DECLINED', null, null],
        ];
    }

    public function testAFailedHoldReversalMarksThePaymentPendingAndDispatchesTheRetry(): void
    {
        Bus::fake([RetryPaymentReversalJob::class]);
        $this->collectReportedExceptions();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andReturn([
            'id' => 'intent-held',
            'status' => 'AUTHORIZED_PENDING_REVIEW',
            'processorInformation' => ['transactionId' => 'tx-held'],
        ]);
        $service->shouldReceive('reversePayment')->once()->andThrow($this->insufficientFundsDecline());
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        try {
            $finalize = $this->finalizeChallenge($paymentId);

            $payment = Payments::find($paymentId);
            $this->assertFalse($finalize['success']);
            $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
            $this->assertTrue($payment->metadata[RetryPaymentReversalJob::PENDING_KEY]);
            $this->assertFalse($this->logsOf($paymentId, '3ds_authorize_declined')->firstOrFail()->metadata['hold_reversed']);
            $this->assertTrue($this->logsOf($paymentId, 'payment_error')->exists());
            Bus::assertDispatched(
                RetryPaymentReversalJob::class,
                fn (RetryPaymentReversalJob $job): bool => $job->payment->getId() === $payment->getId()
            );
        } finally {
            Cache::lock(UniqueLock::getKey(new RetryPaymentReversalJob($this->apps, Payments::find($paymentId))))->forceRelease();
        }
    }

    public function testAHoldWithoutAnAuthorizeIdIsNotReversedAndNoRetryIsDispatched(): void
    {
        Bus::fake([RetryPaymentReversalJob::class]);
        $this->collectReportedExceptions();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andReturn(['status' => 'PARTIAL_AUTHORIZED']);
        $service->shouldNotReceive('reversePayment');
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, Payments::find($paymentId)->status);
        Bus::assertNotDispatched(RetryPaymentReversalJob::class);
    }

    public function testAnUnknownAuthorizeStatusFailsThePaymentWithoutReversingAndIsReported(): void
    {
        $reported = $this->collectReportedExceptions();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('authorizePayment')->once()->andReturn(['id' => 'intent-1', 'status' => 'SOMETHING_NEW']);
        $service->shouldNotReceive('reversePayment');
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, Payments::find($paymentId)->status);
        $this->assertSame(
            'UNKNOWN_AUTHORIZATION_STATUS',
            $this->logsOf($paymentId, '3ds_authorize_declined')->firstOrFail()->error_code
        );
        $unexpected = array_filter(
            $reported->getArrayCopy(),
            fn ($exception): bool => $exception instanceof UnexpectedValueException
        );
        $this->assertCount(1, $unexpected);
        $this->assertStringContainsString('SOMETHING_NEW', array_values($unexpected)[0]->getMessage());
    }

    private function logsOf(string $paymentId, string $eventType): Builder
    {
        return PaymentLogs::query()
            ->where('payments_id', $paymentId)
            ->where('event_type', $eventType);
    }

    private function insufficientFundsDecline(): EchoPayException
    {
        return new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => [
                'errorInformation' => [
                    'reason' => 'INSUFFICIENT_FUND',
                    'message' => 'Decline - Insufficient funds in the account.',
                ],
                'processorInformation' => ['responseCode' => '51'],
                'paymentInsightsInformation' => [
                    'responseInsights' => ['category' => 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME'],
                ],
            ]]
        );
    }
}

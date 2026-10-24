<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Portal;

use Baka\Support\IPInfo;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use DomainException;
use GuzzleHttp\Exception\ConnectException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthenticationInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\DeviceInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\OrderInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\EnforceCardVelocityLimitAction;
use Kanvas\Souk\Payments\Concerns\LogsEchoPayFailures;
use Kanvas\Souk\Payments\Concerns\PersistsEchoPayAuthState;
use Kanvas\Souk\Payments\Concerns\ResolvesEchoPayMerchant;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;
use UnexpectedValueException;

final class PortalProcessor implements PaymentProcessorInterface, TokenizationProcessorInterface, ThreeDSProcessorInterface
{
    use LogsEchoPayFailures;
    use PersistsEchoPayAuthState;
    use ResolvesEchoPayMerchant;

    private const string DEFAULT_REVERSAL_REASON = 'Manual reversal';

    private const string PARTIAL_AMOUNT_MESSAGE = 'Partial amounts are not supported by EchoPay.';

    protected EchoPayService $service;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        ?EchoPayService $service = null,
    ) {
        $this->service = $service ?? new EchoPayService($app, $company);
    }

    public function name(): string
    {
        return 'portal';
    }

    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        throw new DomainException('PortalProcessor requires 3DS — call startChallenge() instead of authorize().');
    }

    public function capture(
        Payments $payment,
        Order $order,
        ?float $amount = null,
        array $context = []
    ): CaptureResult {
        if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return new CaptureResult(
                success: false,
                message: "Cannot capture payment in state '{$payment->status}' — expected AUTHORIZED.",
                transactionId: '',
            );
        }

        if ($this->isPartialAmount($amount, $order)) {
            return new CaptureResult(
                success: false,
                message: self::PARTIAL_AMOUNT_MESSAGE,
                transactionId: '',
            );
        }

        $authorizeTxId = $this->gatewayIntentId($payment);

        if ($authorizeTxId === '') {
            return new CaptureResult(
                success: false,
                message: 'Missing authorize transaction id on payment metadata.',
                transactionId: '',
            );
        }

        $captureAmount = $order->getTotalAmount();
        $input = $this->buildCaptureInput($authorizeTxId, $order, $captureAmount);
        $start = hrtime(true);

        $payment->addLog('payment_capture_sending', [
            'order_id' => $order->id,
            'transaction_id' => $authorizeTxId,
            'amount' => $captureAmount,
        ]);

        try {
            $merchant = $this->setupMerchantAuthentication($payment, $order);
            $response = $this->service->capturePayment($input, $merchant);
        } catch (ConnectException $e) {
            report($e);

            $payment->addLog('payment_capture_timeout', [
                'order_id' => $order->id,
                'transaction_id' => $authorizeTxId,
                'amount' => $captureAmount,
                'error_message' => $e->getMessage(),
                'assumed_captured' => true,
            ]);

            $payment->markAsPaid([
                'data' => [
                    'backup_capture' => true,
                    'capture_timeout' => [
                        'timestamp' => now()->toIso8601String(),
                        'error' => $e->getMessage(),
                    ],
                ],
            ]);

            return new CaptureResult(
                success: true,
                message: 'Payment assumed captured (timeout - needs verification)',
                transactionId: $authorizeTxId,
                raw: ['backup_capture' => true],
            );
        } catch (EchoPayException $e) {
            $this->reportUnlessDecline($e);
            $this->logPaymentError($payment, $e, [
                'error_type' => 'EchoPayException',
                'error_message' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'order_id' => $order->id,
                'authorize_transaction_id' => $authorizeTxId,
                'response_time_ms' => $this->elapsedMs($start),
                'context' => 'capture_payment',
            ]);

            return new CaptureResult(
                success: false,
                message: $e->getUserMessage(),
                transactionId: '',
                raw: $e->getErrorBody(),
            );
        } catch (Throwable $e) {
            $this->reportUnlessDecline($e);
            $this->logPaymentError($payment, $e, [
                'error_type' => $e::class,
                'error_message' => $e->getMessage(),
                'order_id' => $order->id,
                'authorize_transaction_id' => $authorizeTxId,
                'response_time_ms' => $this->elapsedMs($start),
                'context' => 'capture_payment',
            ]);

            return new CaptureResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
            );
        }

        $payment->addLog('payment_captured', [
            'order_id' => $order->id,
            'transaction_id' => $authorizeTxId,
            'amount' => $captureAmount,
        ]);

        $payment->markAsPaid([
            'data' => [
                'capture_data' => $response,
            ],
        ]);

        return new CaptureResult(
            success: true,
            message: 'Payment captured.',
            transactionId: (string) ($response['id'] ?? $authorizeTxId),
            raw: $response,
        );
    }

    public function refund(
        Payments $payment,
        Order $order,
        ?float $amount = null,
        array $context = []
    ): RefundResult {
        if (! in_array($payment->status, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::AUTHORIZED->value], true)) {
            return new RefundResult(
                success: false,
                message: "Cannot refund payment in state '{$payment->status}' — expected PAID or AUTHORIZED.",
                transactionId: '',
            );
        }

        if ($this->isPartialAmount($amount, $order)) {
            return new RefundResult(
                success: false,
                message: self::PARTIAL_AMOUNT_MESSAGE,
                transactionId: '',
            );
        }

        return new RefundResult(...$this->settleReversal(
            $payment,
            $order,
            $context,
            'refund',
            PaymentStatusEnum::REVERSED,
            'Payment refunded.'
        ));
    }

    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return new VoidResult(
                success: false,
                message: "Cannot void payment in state '{$payment->status}' — expected AUTHORIZED (use refund() once PAID).",
                transactionId: '',
            );
        }

        return new VoidResult(...$this->settleReversal(
            $payment,
            $order,
            $context,
            'void',
            PaymentStatusEnum::CANCELLED,
            'Payment voided.'
        ));
    }

    public function verify(Payments $payment, Order $order): VerifyResult
    {
        $status = (string) $payment->status;

        return new VerifyResult(
            success: in_array($status, [PaymentStatusEnum::AUTHORIZED->value, PaymentStatusEnum::PAID->value], true),
            message: "Stored payment status: {$status} (EchoPay has no inquiry endpoint).",
            transactionId: $this->processorTransactionId($payment),
            isoCode: '',
            responseCode: '',
            raw: ['status' => $status],
        );
    }

    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        return $this->service->tokenize($cardDetails, $user);
    }

    public function deleteToken(string $token): bool
    {
        return $this->service->deleteToken($token);
    }

    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        $paymentInstrumentId = (string) ($payment->paymentMethod->stripe_card_id ?? '');

        if ($paymentInstrumentId === '') {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addLog('3ds_setup_payer_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'reason' => 'Missing payment instrument id on payment method',
            ]);

            return new ThreeDSResult(
                success: false,
                message: isset($context['card'])
                    ? 'Portal does not accept raw card data: tokenize with createPaymentMethod (processor: portal), addPaymentToOrder, then startPaymentChallenge.'
                    : 'Payment method has no stored EchoPay token (stripe_card_id).',
                status: PaymentStatusEnum::FAILED->value,
            );
        }

        new EnforceCardVelocityLimitAction($payment)->execute();

        $payment->addLog('payment_initiated', [
            'order_id' => $order->id,
            'amount' => $payment->amount,
        ]);
        $payment->addLog('3ds_setup_payer_start', [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'payment_id' => $payment->id,
        ]);

        $start = hrtime(true);

        try {
            $merchant = $this->setupMerchantAuthentication($payment, $order);
            $response = $this->service->setupPayer((string) $order->id, $paymentInstrumentId, $merchant);
            $responseTimeMs = $this->elapsedMs($start);

            $consumerAuth = $response['consumerAuthenticationInformation'] ?? [];
            $referenceId = (string) ($consumerAuth['referenceId'] ?? '');
            $accessToken = (string) ($consumerAuth['accessToken'] ?? '');
            $deviceDataCollectionUrl = (string) ($consumerAuth['deviceDataCollectionUrl'] ?? '');
            $setupPayerTransactionId = (string) ($response['id'] ?? '');

            $payment->payment_intent_id = $setupPayerTransactionId;
            $payment->addMetadata([
                'data' => [
                    'echo_pay_setup_payer_id' => $setupPayerTransactionId,
                    'echo_pay_reference_id' => $referenceId,
                    'echo_pay_access_token' => $accessToken,
                    'echo_pay_device_data_collection_url' => $deviceDataCollectionUrl,
                ],
            ]);
            $payment->update(['status' => PaymentStatusEnum::WAITING_DEVICE_DATA->value]);

            $order->set('auth_session_id', $referenceId);
            $order->set('device_data_url', $deviceDataCollectionUrl);
            $order->set('access_token', $accessToken);
            $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_STATUS->value, 'waiting_device_data');

            $payment->addLog('3ds_setup_payer_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'transaction_id' => $setupPayerTransactionId,
                'reference_id' => $referenceId,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: true,
                message: '3DS device data collection required — embed device_data_collection_url in a hidden iframe, then call finalizeChallenge.',
                status: PaymentStatusEnum::WAITING_DEVICE_DATA->value,
                data: $this->withLegacyAliases([
                    'transaction_id' => $setupPayerTransactionId,
                    'reference_id' => $referenceId,
                    'access_token' => $accessToken,
                    'device_data_collection_url' => $deviceDataCollectionUrl,
                ]),
                raw: $response,
            );
        } catch (Throwable $e) {
            return $this->failThreeDS(
                $payment,
                $order,
                $e,
                '3ds_setup_payer_failed',
                $start
            );
        }
    }

    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        return match ($payment->status) {
            PaymentStatusEnum::WAITING_DEVICE_DATA->value => $this->runWhileProcessing(
                $payment,
                fn (): ThreeDSResult => $this->runCheckEnrollment($payment, $order)
            ),
            PaymentStatusEnum::PENDING_AUTHORIZATION->value => $this->runWhileProcessing(
                $payment,
                fn (): ThreeDSResult => $this->runValidateAndAuthorize($payment, $order)
            ),
            PaymentStatusEnum::AUTHORIZED->value => $this->alreadyAuthorized($payment),
            default => new ThreeDSResult(
                success: false,
                message: "finalizeChallenge called with payment in state '{$payment->status}' — expected WAITING_DEVICE_DATA or PENDING_AUTHORIZATION.",
                status: $payment->status ?? PaymentStatusEnum::FAILED->value,
            ),
        };
    }

    private function runWhileProcessing(Payments $payment, callable $step): ThreeDSResult
    {
        $previousStatus = $payment->status;

        if (! $payment->transitionStatus($previousStatus, PaymentStatusEnum::PROCESSING->value)) {
            return new ThreeDSResult(
                success: false,
                message: 'Payment is already being processed.',
                status: PaymentStatusEnum::PROCESSING->value,
            );
        }

        try {
            return $step();
        } catch (Throwable $e) {
            if ($payment->status === PaymentStatusEnum::PROCESSING->value) {
                $payment->update(['status' => $previousStatus]);
            }

            throw $e;
        }
    }

    private function alreadyAuthorized(Payments $payment): ThreeDSResult
    {
        return new ThreeDSResult(
            success: true,
            message: 'Payment already authorized — call capture() to settle.',
            status: PaymentStatusEnum::AUTHORIZED->value,
            data: [
                'intent_id' => (string) ($payment->payment_intent_id ?? ''),
                'transaction_id' => $this->processorTransactionId($payment),
            ],
        );
    }

    private function runCheckEnrollment(Payments $payment, Order $order): ThreeDSResult
    {
        $referenceId = (string) ($order->get('auth_session_id') ?? '');

        if ($referenceId === '') {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);

            return new ThreeDSResult(
                success: false,
                message: 'Missing referenceId — startChallenge was never called for this payment.',
                status: PaymentStatusEnum::FAILED->value,
            );
        }

        $start = hrtime(true);

        try {
            $merchant = $this->setupMerchantAuthentication($payment, $order);
            $paymentDetail = $this->buildEnrollmentPaymentDetail($payment, $order, $referenceId);
            $response = $this->service->checkPayerEnrollment($paymentDetail, $merchant);
        } catch (Throwable $e) {
            return $this->failAuthenticationStage($payment, 'enrollment', $e);
        }

        $responseTimeMs = $this->elapsedMs($start);

        /** @var ConsumerAuthentication $consumerData */
        $consumerData = $response['consumerAuthenticationInformation'];
        $authTransactionId = (string) ($response['id'] ?? '');

        $payment->addMetadata([
            'data' => [
                'echo_pay_enrollment_status' => $response['status'] ?? null,
            ],
        ]);
        $payment->save();

        if ($this->isValidEci($consumerData, $response)) {
            $payment->addLog('3ds_enrollment_frictionless', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'auth_transaction_id' => $authTransactionId,
                'response_time_ms' => $responseTimeMs,
            ]);

            return $this->runAuthorize($payment, $order, $consumerData);
        }

        return $this->requireUserValidation(
            $payment,
            $order,
            $response,
            $consumerData,
            '3DS authentication failed at enrollment.'
        );
    }

    private function requireUserValidation(
        Payments $payment,
        Order $order,
        array $response,
        ConsumerAuthentication $consumerData,
        string $fallbackMessage
    ): ThreeDSResult {
        $enrollmentStatus = (string) ($response['status'] ?? '');
        $paymentStatus = $this->mapEnrollmentStatusToPaymentStatus($enrollmentStatus);

        $this->rememberAuthTransactionId($order, $consumerData);

        if ($paymentStatus === null) {
            report(new UnexpectedValueException('Unmapped EchoPay enrollment status: ' . $enrollmentStatus));
        }

        if ($paymentStatus !== PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
            return $this->failPayerAuthentication(
                $payment,
                $order,
                $response,
                $consumerData,
                $fallbackMessage
            );
        }

        $payment->addMetadata(['enrollment_data' => $response]);
        $payment->update(['status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value]);
        $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($response));
        $order->set('authorization_data', json_encode($consumerData));
        $order->updateQuietly(['status' => OrderStatusEnum::PENDING->value]);

        $authTransactionId = (string) ($response['id'] ?? '');
        $payment->addLog('3ds_challenge_required', [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'auth_transaction_id' => $authTransactionId,
        ]);

        return new ThreeDSResult(
            success: true,
            message: '3DS challenge required — redirect the cardholder to acs_url with pareq, then call finalizeChallenge again.',
            status: PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            data: $this->withLegacyAliases([
                'auth_transaction_id' => $authTransactionId,
                'enrollment_status' => $enrollmentStatus,
                'acs_url' => $consumerData->acsUrl ?? '',
                'step_up_url' => $consumerData->stepUpUrl ?? '',
                'pareq' => $consumerData->pareq ?? '',
                'access_token' => $consumerData->accessToken ?? '',
                'authenticationTransactionId' => $consumerData->authenticationTransactionId ?? '',
                'returnUrl' => (string) ($this->app->get(ConfigurationEnum::REDIRECT_URL->value) ?? ''),
                'consumer_authentication' => $consumerData->toArray(),
            ]),
            raw: $response,
        );
    }

    private function withLegacyAliases(array $data): array
    {
        $aliases = [
            'device_data_collection_url' => 'deviceDataUrl',
            'reference_id' => 'referenceId',
            'acs_url' => 'acsUrl',
            'step_up_url' => 'stepUpUrl',
            'access_token' => 'accessToken',
        ];

        foreach ($aliases as $key => $alias) {
            if (array_key_exists($key, $data)) {
                $data[$alias] = $data[$key];
            }
        }

        return $data;
    }

    private function runValidateAndAuthorize(Payments $payment, Order $order): ThreeDSResult
    {
        $authTransactionId = (string) ($order->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value) ?? '');

        if ($authTransactionId === '') {
            $payment->update(['status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value]);

            return new ThreeDSResult(
                success: false,
                message: 'Missing auth_transaction_id — checkPayerEnrollment was never completed for this payment.',
                status: PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            );
        }

        $start = hrtime(true);

        try {
            $merchant = $this->setupMerchantAuthentication($payment, $order);
            $paymentDetail = $this->buildEnrollmentPaymentDetail($payment, $order, referenceId: null);
            $response = $this->service->validatePayerAuthResult($authTransactionId, $paymentDetail, $merchant);
        } catch (Throwable $e) {
            return $this->failAuthenticationStage($payment, 'validate_auth_result', $e);
        }

        $responseTimeMs = $this->elapsedMs($start);

        /** @var ConsumerAuthentication $consumerData */
        $consumerData = $response['consumerAuthenticationInformation'];

        if (! $this->isValidEci($consumerData, $response)) {
            return $this->requireUserValidation(
                $payment,
                $order,
                $response,
                $consumerData,
                '3DS challenge validation failed (ECI not approved).'
            );
        }

        $payment->addLog('3ds_validate_success', [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'auth_transaction_id' => $authTransactionId,
            'response_time_ms' => $responseTimeMs,
        ]);

        return $this->runAuthorize($payment, $order, $consumerData);
    }

    private function runAuthorize(Payments $payment, Order $order, ConsumerAuthentication $consumerData): ThreeDSResult
    {
        $payment->addLog('payment_processing', [
            'order_id' => $order->id,
            'amount' => $order->getTotalAmount(),
        ]);

        $start = hrtime(true);

        try {
            $merchant = $this->setupMerchantAuthentication($payment, $order, includeDetails: true);
            $paymentDetail = $this->buildAuthorizePaymentDetail($payment, $order, $merchant->id);
            $response = $this->service->authorizePayment($paymentDetail, $consumerData, $merchant);
        } catch (Throwable $e) {
            $this->cancelOrderAfterDecline($order);

            return $this->failThreeDS(
                $payment,
                $order,
                $e,
                '3ds_authorize_failed',
                $start
            );
        }

        $responseTimeMs = $this->elapsedMs($start);

        $authorized = ($response['status'] ?? null) === 'AUTHORIZED';

        if (! $authorized) {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addLog('3ds_authorize_declined', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'status' => $response['status'] ?? null,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: false,
                message: 'Authorization declined by EchoPay (status: ' . ($response['status'] ?? 'unknown') . ').',
                status: PaymentStatusEnum::FAILED->value,
                raw: $response,
            );
        }

        $intentId = (string) ($response['id'] ?? '');
        $transactionId = (string) ($response['processorInformation']['transactionId'] ?? '');

        $payment->status = PaymentStatusEnum::AUTHORIZED->value;
        $payment->payment_intent_id = $intentId;
        $payment->addMetadata([
            'data' => [
                'payment_response' => $response,
                'echo_pay_authorize_transaction_id' => $transactionId,
            ],
        ]);
        $payment->save();

        $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $intentId);
        $order->set(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value, $transactionId);
        $order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);

        $payment->addLog('payment_authorized', [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'intent_id' => $intentId,
            'transaction_id' => $transactionId,
            'response_time_ms' => $responseTimeMs,
        ]);

        return new ThreeDSResult(
            success: true,
            message: 'Payment authorized — call capture() to settle.',
            status: PaymentStatusEnum::AUTHORIZED->value,
            data: [
                'intent_id' => $intentId,
                'transaction_id' => $transactionId,
            ],
            raw: $response,
        );
    }

    private function isValidEci(ConsumerAuthentication $consumerData, array $enrollmentData): bool
    {
        if (($enrollmentData['status'] ?? null) !== EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value) {
            return false;
        }

        $eci = $consumerData->eci ?? $consumerData->eciRaw;
        $hasValidEci = in_array($eci, ['02', '05'], strict: true);

        if (isset($enrollmentData['paymentInformation']['card']['type'])) {
            $cardBrand = $enrollmentData['paymentInformation']['card']['type'];
            $isEciMissing = ($enrollmentData['status'] === EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value) && empty($consumerData->eci);
            $byPassEci = (bool) $this->app->get(ConfigurationEnum::BYPASS_ECI->value);

            if ($cardBrand === 'MASTERCARD' && $isEciMissing && $byPassEci) {
                return true;
            }
        }

        return $hasValidEci;
    }

    private function buildEnrollmentPaymentDetail(Payments $payment, Order $order, ?string $referenceId): PaymentDetail
    {
        return new PaymentDetail(
            orderCode: (string) $order->id,
            paymentInstrumentId: (string) $payment->paymentMethod->stripe_card_id,
            orderInformation: $this->buildOrderInformation($payment, $order),
            deviceInformation: new DeviceInformation(
                httpAcceptContent: 'application/json',
                httpBrowserLanguage: 'en_us',
                userAgentBrowserValue: 'chrome',
            ),
            consumerAuthenticationInformation: $referenceId !== null && $referenceId !== ''
                ? new ConsumerAuthenticationInformation(
                    deviceChannel: 'BROWSER',
                    referenceId: $referenceId,
                    transactionMode: 'eCommerce',
                    returnUrl: (string) ($this->app->get(ConfigurationEnum::REDIRECT_URL->value) ?? ''),
                )
                : null,
        );
    }

    private function buildAuthorizePaymentDetail(Payments $payment, Order $order, string $merchantId): PaymentDetail
    {
        return new PaymentDetail(
            orderCode: (string) $order->id,
            paymentInstrumentId: (string) $payment->paymentMethod->stripe_card_id,
            orderInformation: $this->buildOrderInformation($payment, $order),
            deviceInformation: new DeviceInformation(
                ipAddress: $order->metadata['data']['user_ip'] ?? IPInfo::getClientIp(),
                fingerprintSessionId: $merchantId . $order->id,
            ),
            consumerAuthenticationInformation: new ConsumerAuthenticationInformation(
                deviceChannel: 'BROWSER',
                referenceId: (string) ($order->get('auth_session_id') ?? ''),
                transactionMode: 'eCommerce',
            ),
        );
    }

    private function buildOrderInformation(Payments $payment, Order $order): OrderInformation
    {
        return new OrderInformation(
            currency: 'DOP',
            totalAmount: (string) $order->getTotalAmount(),
            billTo: $this->buildBillingDetail($payment),
        );
    }

    private function buildBillingDetail(Payments $payment): BillingDetail
    {
        $paymentMethod = $payment->paymentMethod;
        $user = $payment->user;

        return new BillingDetail(
            firstName: (string) ($paymentMethod->getMetadata('firstname') ?? $user->firstname ?? ''),
            lastName: (string) ($paymentMethod->getMetadata('lastname') ?? $user->lastname ?? ''),
            address1: (string) ($paymentMethod->getMetadata('address') ?? ''),
            city: (string) ($paymentMethod->getMetadata('city') ?? ''),
            administrativeArea: (string) ($paymentMethod->getMetadata('state') ?? ''),
            postalCode: (string) ($paymentMethod->getMetadata('zip_code') ?? ''),
            country: (string) ($paymentMethod->getMetadata('country') ?? ''),
            email: (string) ($user->email ?? ''),
            phone: (string) ($paymentMethod->getMetadata('phone') ?? ''),
        );
    }

    /**
     * @return array{success: bool, message: string, transactionId: string, raw: array}
     */
    private function doReverse(
        Payments $payment,
        Order $order,
        float $amount,
        string $reason,
        string $logTag
    ): array {
        $authorizeTxId = $this->gatewayIntentId($payment);

        if ($authorizeTxId === '') {
            return [
                'success' => false,
                'message' => 'Missing authorize transaction id on payment metadata.',
                'transactionId' => '',
                'raw' => [],
            ];
        }

        $input = $this->buildCaptureInput($authorizeTxId, $order, $amount);
        $start = hrtime(true);

        try {
            $merchant = $this->setupMerchantAuthentication($payment, $order);
            $response = $this->service->reversePayment($input, $merchant, $reason);
        } catch (EchoPayException $e) {
            $this->reportUnlessDecline($e);
            $this->logPaymentError($payment, $e, [
                'error_type' => 'EchoPayException',
                'error_message' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'order_id' => $order->id,
                'authorize_transaction_id' => $authorizeTxId,
                'reason' => $reason,
                'response_time_ms' => $this->elapsedMs($start),
                'context' => 'reverse_payment',
                'operation' => $logTag,
            ]);

            return $this->failedReversal($e->getUserMessage(), $e->getErrorBody());
        } catch (Throwable $e) {
            $this->reportUnlessDecline($e);
            $this->logPaymentError($payment, $e, [
                'error_type' => $e::class,
                'error_message' => $e->getMessage(),
                'order_id' => $order->id,
                'authorize_transaction_id' => $authorizeTxId,
                'reason' => $reason,
                'response_time_ms' => $this->elapsedMs($start),
                'context' => 'reverse_payment',
                'operation' => $logTag,
            ]);

            return $this->failedReversal($e->getMessage(), []);
        }

        $responseTimeMs = $this->elapsedMs($start);
        $reverseTxId = (string) ($response['id'] ?? $authorizeTxId);

        $payment->addMetadata([
            'data' => [
                'reverse_data' => $response,
            ],
        ]);

        $payment->save();

        $payment->addLog('payment_reversed', [
            'transaction_id' => $authorizeTxId,
            'reason' => $reason,
            'order_id' => $order->id,
            'amount' => $amount,
            'operation' => $logTag,
            'response_time_ms' => $responseTimeMs,
        ]);

        return [
            'success' => true,
            'message' => "Payment {$logTag} processed.",
            'transactionId' => $reverseTxId,
            'raw' => $response,
        ];
    }

    private function settleReversal(
        Payments $payment,
        Order $order,
        array $context,
        string $operation,
        PaymentStatusEnum $finalStatus,
        string $successMessage
    ): array {
        $result = $this->doReverse(
            $payment,
            $order,
            $order->getTotalAmount(),
            $this->reversalReason($context),
            $operation
        );

        if ($result['success']) {
            $payment->update(['status' => $finalStatus->value]);
            $result['message'] = $successMessage;
        }

        return $result;
    }

    private function failedReversal(string $message, array $raw): array
    {
        return [
            'success' => false,
            'message' => $message,
            'transactionId' => '',
            'raw' => $raw,
        ];
    }

    private function buildCaptureInput(string $transactionId, Order $order, float $amount): PaymentCaptureInput
    {
        $input = new PaymentCaptureInput();
        $input->transactionId = $transactionId;
        $input->orderCode = (string) $order->id;
        $input->currency = 'DOP';
        $input->totalAmount = (string) $amount;

        return $input;
    }

    private function isPartialAmount(?float $amount, Order $order): bool
    {
        return $amount !== null && abs($amount - $order->getTotalAmount()) >= 0.005;
    }

    private function reversalReason(array $context): string
    {
        return Str::trimToNull($context['reason'] ?? null) ?? self::DEFAULT_REVERSAL_REASON;
    }

    private function cancelOrderAfterDecline(Order $order): void
    {
        $order->updateQuietly([
            'status' => OrderStatusEnum::FAILED->value,
            'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
        ]);
    }

    private function gatewayIntentId(Payments $payment): string
    {
        $intentId = (string) ($payment->payment_intent_id ?? '');

        if ($intentId !== '') {
            return $intentId;
        }

        return (string) ($payment->getMetadata('echo_pay_authorize_transaction_id') ?? '');
    }

    private function processorTransactionId(Payments $payment): string
    {
        $stored = (string) ($payment->getMetadata('echo_pay_authorize_transaction_id') ?? '');

        if ($stored !== '') {
            return $stored;
        }

        return (string) ($payment->payment_intent_id ?? '');
    }

    private function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }

    private function failPayerAuthentication(
        Payments $payment,
        Order $order,
        array $response,
        ConsumerAuthentication $consumerData,
        string $fallbackMessage
    ): ThreeDSResult {
        $errors = $this->extractErrorsFromEnrollment($response);

        $payment->addMetadata(['enrollment_data' => $response]);
        $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($response));
        $order->updateQuietly(['status' => OrderStatusEnum::FAILED->value]);
        $this->logAuthenticationFailure(
            $payment,
            $response,
            $consumerData,
            $errors
        );

        return new ThreeDSResult(
            success: false,
            message: $errors['message'] !== '' ? $errors['message'] : $fallbackMessage,
            status: PaymentStatusEnum::FAILED->value,
            raw: $response,
        );
    }

    private function failAuthenticationStage(Payments $payment, string $stage, Throwable $e): ThreeDSResult
    {
        $this->reportUnlessDecline($e);

        if ($e instanceof EchoPayException) {
            $this->failAuthentication(
                $payment,
                $stage,
                [
                    'error' => $e->getMessage(),
                    'echopay_error' => $e->getErrorBody(),
                    'user_message' => $e->getUserMessage(),
                    'echopay_error_timestamp' => now()->toIso8601String(),
                ],
                $e->toPaymentFailure()
            );

            return new ThreeDSResult(
                success: false,
                message: $e->getUserMessage(),
                status: PaymentStatusEnum::FAILED->value,
                raw: $e->getErrorBody(),
            );
        }

        $errorMessage = $this->resolveErrorMessage($e);

        $this->failAuthentication(
            $payment,
            $stage,
            [
                'error' => $errorMessage,
            ],
            $this->paymentFailure($e, $errorMessage)
        );

        return new ThreeDSResult(
            success: false,
            message: $errorMessage,
            status: PaymentStatusEnum::FAILED->value,
        );
    }

    private function failThreeDS(
        Payments $payment,
        Order $order,
        Throwable $e,
        string $logEvent,
        int|float $start
    ): ThreeDSResult {
        $this->reportUnlessDecline($e);

        $errorMessage = $e instanceof EchoPayException ? $e->getMessage() : $this->resolveErrorMessage($e);
        $failureMetadata = $e instanceof EchoPayException
            ? [
                'error' => $errorMessage,
                'echopay_error' => $e->getErrorBody(),
                'user_message' => $e->getUserMessage(),
                'echopay_error_timestamp' => now()->toIso8601String(),
            ]
            : [
                'error' => $errorMessage,
                'message_body' => $this->resolveGatewayMessageBody($e),
            ];

        $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        $payment->addMetadata(['data' => $failureMetadata]);
        $payment->addLog(
            $logEvent,
            [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $errorMessage,
                ...($e instanceof EchoPayException ? ['error_body' => $e->getErrorBody()] : ['error_class' => $e::class]),
                'response_time_ms' => $this->elapsedMs($start),
            ],
            $this->paymentFailure($e, $errorMessage)
        );

        return new ThreeDSResult(
            success: false,
            message: $e instanceof EchoPayException ? $e->getUserMessage() : $errorMessage,
            status: PaymentStatusEnum::FAILED->value,
            raw: $e instanceof EchoPayException ? $e->getErrorBody() : [],
        );
    }
}

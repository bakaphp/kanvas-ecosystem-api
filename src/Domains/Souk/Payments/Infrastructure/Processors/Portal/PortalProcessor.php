<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Portal;

use Baka\Support\IPInfo;
use Baka\Users\Contracts\UserInterface;
use DomainException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthenticationInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\DeviceInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDefinedInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\OrderInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Models\Order;
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

/**
 * Portal / EchoPay payment processor (Dominican Republic).
 *
 * New, interface-based replacement for the legacy PortalPaymentProcessor under
 * Kanvas\Souk\Payments\Providers. The legacy class continues to back existing
 * production endpoints and is not affected by this processor.
 *
 * Capabilities:
 *   - PaymentProcessorInterface      : authorize / capture / refund / void / verify
 *   - TokenizationProcessorInterface : tokenize / deleteToken (delegates to EchoPayService)
 *   - ThreeDSProcessorInterface      : startChallenge / finalizeChallenge
 *
 * Flow:
 *   1. tokenize()          — store card in EchoPay vault, return reusable token.
 *   2. startChallenge()    — setupPayer + checkPayerEnrollment; returns device-data
 *                            collection URL or proceeds straight to authorize when ECI
 *                            indicates frictionless authentication.
 *   3. finalizeChallenge() — validatePayerAuthResult after the browser step; on success
 *                            authorizePayment is called to charge.
 *   4. authorize()         — entry point that gates straight-through (non-3DS) callers;
 *                            EchoPay always requires 3DS so this throws and points the
 *                            caller at startChallenge().
 *   5. capture() / refund() / void() / verify() — post-authorization operations.
 */
final class PortalProcessor implements PaymentProcessorInterface, TokenizationProcessorInterface, ThreeDSProcessorInterface
{
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

    // -------------------------------------------------------------------------
    // PaymentProcessorInterface
    // -------------------------------------------------------------------------

    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        throw new DomainException('PortalProcessor requires 3DS — call startChallenge() instead of authorize().');
    }

    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        throw new DomainException('PortalProcessor::capture() not implemented yet.');
    }

    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        throw new DomainException('PortalProcessor::refund() not implemented yet.');
    }

    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        throw new DomainException('PortalProcessor::void() not implemented yet.');
    }

    public function verify(Payments $payment, Order $order): VerifyResult
    {
        throw new DomainException('PortalProcessor::verify() not implemented yet.');
    }

    // -------------------------------------------------------------------------
    // TokenizationProcessorInterface
    // -------------------------------------------------------------------------

    /**
     * Tokenize a card with the EchoPay vault.
     * Delegates to EchoPayService, which already implements TokenizationProcessorInterface.
     */
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        return $this->service->tokenize($cardDetails, $user);
    }

    /**
     * Remove a previously tokenized card from the EchoPay vault.
     */
    public function deleteToken(string $token): bool
    {
        return $this->service->deleteToken($token);
    }

    // -------------------------------------------------------------------------
    // ThreeDSProcessorInterface
    // -------------------------------------------------------------------------

    /**
     * Initiate the EchoPay 3DS flow by calling setupPayer.
     *
     * Returns the deviceDataCollectionUrl + access token the browser needs to
     * embed in a hidden iframe so EchoPay can fingerprint the cardholder's
     * device. Once that fingerprinting completes client-side, the caller invokes
     * finalizeChallenge() to run checkPayerEnrollment + validatePayerAuthResult
     * and (when ECI indicates a frictionless flow) authorize the payment.
     *
     * Payment transitions to WAITING_DEVICE_DATA. The setupPayer transaction id,
     * referenceId, and accessToken are persisted on payment metadata so they
     * survive the round-trip back from the browser. The referenceId is also
     * mirrored onto order.auth_session_id to match the contract used by the
     * legacy PortalPaymentProcessor.
     */
    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        $payment->addLog('3ds_setup_payer_start', [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'payment_id' => $payment->id,
        ]);

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
                message: 'Payment method has no stored EchoPay token (stripe_card_id).',
                status: PaymentStatusEnum::FAILED->value,
            );
        }

        $merchant = $this->buildMerchant();
        $start = hrtime(true);

        try {
            $response = $this->service->setupPayer((string) $order->id, $paymentInstrumentId, $merchant);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

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

            // Mirror legacy contract: subsequent enrollment/auth calls read this off the order.
            $order->set('auth_session_id', $referenceId);

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
                data: [
                    'transaction_id' => $setupPayerTransactionId,
                    'reference_id' => $referenceId,
                    'access_token' => $accessToken,
                    'device_data_collection_url' => $deviceDataCollectionUrl,
                ],
                raw: $response,
            );
        } catch (EchoPayException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addMetadata([
                'data' => [
                    'error' => $e->getMessage(),
                    'echopay_error' => $e->getErrorBody(),
                    'echopay_error_timestamp' => now()->toIso8601String(),
                ],
            ]);
            $payment->addLog('3ds_setup_payer_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: false,
                message: $e->getUserMessage(),
                status: PaymentStatusEnum::FAILED->value,
                raw: $e->getErrorBody(),
            );
        } catch (Throwable $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addMetadata([
                'data' => [
                    'error' => $e->getMessage(),
                ],
            ]);
            $payment->addLog('3ds_setup_payer_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: false,
                message: $e->getMessage(),
                status: PaymentStatusEnum::FAILED->value,
            );
        }
    }

    /**
     * Finalize the EchoPay 3DS flow after the browser-side device data step
     * (and, if required, a separate ACS challenge) completes.
     *
     * State machine driven off Payments::status:
     *
     *   WAITING_DEVICE_DATA       (set by startChallenge)
     *      -> checkPayerEnrollment
     *         - ECI valid (frictionless)  -> authorize    -> AUTHORIZED
     *         - challenge required        -> persist auth_transaction_id,
     *                                        return acsUrl/stepUpUrl/pareq,
     *                                        status: PENDING_AUTHORIZATION
     *         - hard failure              -> FAILED
     *
     *   PENDING_AUTHORIZATION    (set above, after challenge is completed)
     *      -> validatePayerAuthResult
     *         - ECI valid                 -> authorize    -> AUTHORIZED
     *         - otherwise                 -> FAILED
     *
     * The actual capture (PAID transition) is handled by capture() in a
     * subsequent phase, mirroring EchoPay's asynchronous auth/capture split.
     */
    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        return match ($payment->status) {
            PaymentStatusEnum::WAITING_DEVICE_DATA->value => $this->runCheckEnrollment($payment, $order),
            PaymentStatusEnum::PENDING_AUTHORIZATION->value => $this->runValidateAndAuthorize($payment, $order),
            default => new ThreeDSResult(
                success: false,
                message: "finalizeChallenge called with payment in state '{$payment->status}' — expected WAITING_DEVICE_DATA or PENDING_AUTHORIZATION.",
                status: $payment->status ?? PaymentStatusEnum::FAILED->value,
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Helpers — 3DS orchestration
    // -------------------------------------------------------------------------

    /**
     * Step 2 — checkPayerEnrollment with the reference id collected during setupPayer.
     */
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

        $merchant = $this->buildMerchant();
        $paymentDetail = $this->buildEnrollmentPaymentDetail($payment, $order, $referenceId);
        $start = hrtime(true);

        try {
            $response = $this->service->checkPayerEnrollment($paymentDetail, $merchant);
        } catch (EchoPayException $e) {
            return $this->failWithEchoPayException($payment, $order, $e, '3ds_check_enrollment_failed', $start);
        } catch (Throwable $e) {
            return $this->failWithThrowable($payment, $order, $e, '3ds_check_enrollment_failed', $start);
        }

        $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

        /** @var ConsumerAuthentication $consumerData */
        $consumerData = $response['consumerAuthenticationInformation'];
        $authTransactionId = (string) ($response['id'] ?? '');

        // Persist the enrollment transaction id so a later validatePayerAuthResult call can find it.
        $payment->addMetadata([
            'data' => [
                'echo_pay_auth_transaction_id' => $authTransactionId,
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

        // Hard authentication failure — no point continuing.
        $enrollmentStatus = $response['status'] ?? null;

        if ($enrollmentStatus === EchoPayStatusEnum::AUTHENTICATION_FAILED->value) {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addLog('3ds_enrollment_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'enrollment_status' => $enrollmentStatus,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: false,
                message: '3DS authentication failed at enrollment.',
                status: PaymentStatusEnum::FAILED->value,
                raw: $response,
            );
        }

        // Soft case — challenge needed. Caller redirects to acsUrl/stepUpUrl with pareq.
        $payment->update(['status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value]);
        $payment->addLog('3ds_challenge_required', [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'auth_transaction_id' => $authTransactionId,
            'response_time_ms' => $responseTimeMs,
        ]);

        return new ThreeDSResult(
            success: true,
            message: '3DS challenge required — redirect the cardholder to acs_url with pareq, then call finalizeChallenge again.',
            status: PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            data: [
                'auth_transaction_id' => $authTransactionId,
                'enrollment_status' => $enrollmentStatus,
                'acs_url' => $consumerData->acsUrl ?? '',
                'step_up_url' => $consumerData->stepUpUrl ?? '',
                'pareq' => $consumerData->pareq ?? '',
                'access_token' => $consumerData->accessToken ?? '',
                'consumer_authentication' => $consumerData->toArray(),
            ],
            raw: $response,
        );
    }

    /**
     * Step 4 — validatePayerAuthResult with the auth transaction id persisted by step 2;
     * step 5 — authorizePayment once the ECI looks good.
     */
    private function runValidateAndAuthorize(Payments $payment, Order $order): ThreeDSResult
    {
        $authTransactionId = (string) ($payment->getMetadata('echo_pay_auth_transaction_id') ?? '');

        if ($authTransactionId === '') {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);

            return new ThreeDSResult(
                success: false,
                message: 'Missing auth_transaction_id — checkPayerEnrollment was never completed for this payment.',
                status: PaymentStatusEnum::FAILED->value,
            );
        }

        $merchant = $this->buildMerchant();
        $paymentDetail = $this->buildEnrollmentPaymentDetail($payment, $order, referenceId: null);
        $start = hrtime(true);

        try {
            $response = $this->service->validatePayerAuthResult($authTransactionId, $paymentDetail, $merchant);
        } catch (EchoPayException $e) {
            return $this->failWithEchoPayException($payment, $order, $e, '3ds_validate_failed', $start);
        } catch (Throwable $e) {
            return $this->failWithThrowable($payment, $order, $e, '3ds_validate_failed', $start);
        }

        $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

        /** @var ConsumerAuthentication $consumerData */
        $consumerData = $response['consumerAuthenticationInformation'];

        if (! $this->isValidEci($consumerData, $response)) {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addLog('3ds_validate_eci_invalid', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'eci' => $consumerData->eci ?? $consumerData->eciRaw ?? null,
                'status' => $response['status'] ?? null,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: false,
                message: '3DS challenge validation failed (ECI not approved).',
                status: PaymentStatusEnum::FAILED->value,
                raw: $response,
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

    /**
     * Step 5 — call authorizePayment to actually charge. EchoPay is auth/capture-split,
     * so a successful authorize moves the payment to AUTHORIZED, not PAID. capture()
     * (Phase 5+) finishes the transaction.
     */
    private function runAuthorize(Payments $payment, Order $order, ConsumerAuthentication $consumerData): ThreeDSResult
    {
        $merchant = $this->buildMerchantWithDetails($payment);
        $paymentDetail = $this->buildAuthorizePaymentDetail($payment, $order);
        $start = hrtime(true);

        try {
            $response = $this->service->authorizePayment($paymentDetail, $consumerData, $merchant);
        } catch (EchoPayException $e) {
            return $this->failWithEchoPayException($payment, $order, $e, '3ds_authorize_failed', $start);
        } catch (Throwable $e) {
            return $this->failWithThrowable($payment, $order, $e, '3ds_authorize_failed', $start);
        }

        $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

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
                'echo_pay_authorize_response' => $response,
                'echo_pay_authorize_transaction_id' => $transactionId,
            ],
        ]);
        $payment->save();

        $payment->addLog('3ds_authorize_success', [
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

    /**
     * Port of legacy PortalPaymentProcessor::isValidEci.
     *
     * Authoritative ECI values (Visa/Master ranges 02/05 mean "fully authenticated").
     * MasterCard sometimes returns a successful authentication with the ECI field
     * missing — when BYPASS_ECI is on we treat that as success too.
     */
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

    // -------------------------------------------------------------------------
    // Helpers — DTO construction
    // -------------------------------------------------------------------------

    /**
     * Build the basic MerchantDetail used for setupPayer / checkPayerEnrollment /
     * validatePayerAuthResult — no merchantDefinedInformation block.
     *
     * Multi-merchant (per-order-type) routing — present in the legacy
     * PortalPaymentProcessor via portal_multy_merchant + {ORDER_TYPE}_ECHO_PAY_*
     * keys — will be ported in a follow-up phase.
     */
    private function buildMerchant(): MerchantDetail
    {
        return MerchantDetail::from([
            'id' => (string) ($this->app->get('ECHO_PAY_MERCHANT_ID') ?? ''),
            'key' => (string) ($this->app->get('ECHO_PAY_MERCHANT_KEY') ?? ''),
            'secretKey' => (string) ($this->app->get('ECHO_PAY_MERCHANT_SECRET') ?? ''),
        ]);
    }

    /**
     * Build the MerchantDetail used for authorizePayment, which also needs
     * the merchantDefinedInformation block (customer id, document, etc.).
     */
    private function buildMerchantWithDetails(Payments $payment): MerchantDetail
    {
        $merchantId = (string) ($this->app->get('ECHO_PAY_MERCHANT_ID') ?? '');

        return new MerchantDetail(
            id: $merchantId,
            key: (string) ($this->app->get('ECHO_PAY_MERCHANT_KEY') ?? ''),
            secretKey: (string) ($this->app->get('ECHO_PAY_MERCHANT_SECRET') ?? ''),
            merchantDefinedInformation: new MerchantDefinedInformation(
                category: MerchantCategoryEnum::RETAIL,
                cardIdentifier: $merchantId,
                platform: MerchantPlatformEnum::MOBILE,
                customerId: 'user_' . $payment->user->id,
                tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                documentType: MerchantDocumentTypesEnum::DNI,
                documentNumber: (string) ($payment->user->get('driver_license') ?? ''),
            ),
        );
    }

    /**
     * PaymentDetail used for the enrollment / validate steps — minimal device info,
     * no billTo on the order information (saves rebuilding it before authorize).
     */
    private function buildEnrollmentPaymentDetail(Payments $payment, Order $order, ?string $referenceId): PaymentDetail
    {
        return new PaymentDetail(
            orderCode: (string) $order->id,
            paymentInstrumentId: (string) $payment->paymentMethod->stripe_card_id,
            orderInformation: new OrderInformation(
                currency: $order->currency ?: 'DOP',
                totalAmount: (string) $order->getTotalAmount(),
                billTo: $this->buildBillingDetail($payment),
            ),
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

    /**
     * PaymentDetail used for authorizePayment — full billTo, real IP + fingerprint.
     */
    private function buildAuthorizePaymentDetail(Payments $payment, Order $order): PaymentDetail
    {
        $merchantId = (string) ($this->app->get('ECHO_PAY_MERCHANT_ID') ?? '');

        return new PaymentDetail(
            orderCode: (string) $order->id,
            paymentInstrumentId: (string) $payment->paymentMethod->stripe_card_id,
            orderInformation: new OrderInformation(
                currency: $order->currency ?: 'DOP',
                totalAmount: (string) $order->getTotalAmount(),
                billTo: $this->buildBillingDetail($payment),
            ),
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

    // -------------------------------------------------------------------------
    // Helpers — error handling
    // -------------------------------------------------------------------------

    private function failWithEchoPayException(Payments $payment, Order $order, EchoPayException $e, string $logEvent, int $start): ThreeDSResult
    {
        $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

        $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        $payment->addMetadata([
            'data' => [
                'error' => $e->getMessage(),
                'echopay_error' => $e->getErrorBody(),
                'echopay_error_timestamp' => now()->toIso8601String(),
            ],
        ]);
        $payment->addLog($logEvent, [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'error' => $e->getMessage(),
            'error_body' => $e->getErrorBody(),
            'response_time_ms' => $responseTimeMs,
        ]);

        return new ThreeDSResult(
            success: false,
            message: $e->getUserMessage(),
            status: PaymentStatusEnum::FAILED->value,
            raw: $e->getErrorBody(),
        );
    }

    private function failWithThrowable(Payments $payment, Order $order, Throwable $e, string $logEvent, int $start): ThreeDSResult
    {
        $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

        $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        $payment->addMetadata([
            'data' => [
                'error' => $e->getMessage(),
            ],
        ]);
        $payment->addLog($logEvent, [
            'processor' => $this->name(),
            'order_id' => $order->id,
            'error' => $e->getMessage(),
            'error_class' => $e::class,
            'response_time_ms' => $responseTimeMs,
        ]);

        return new ThreeDSResult(
            success: false,
            message: $e->getMessage(),
            status: PaymentStatusEnum::FAILED->value,
        );
    }
}

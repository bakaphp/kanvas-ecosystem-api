<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Portal;

use Baka\Users\Contracts\UserInterface;
use DomainException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
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

    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        throw new DomainException('PortalProcessor::finalizeChallenge() not implemented yet — see Phase 4.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the MerchantDetail used for setupPayer.
     *
     * Pulls credentials from the app's default ECHO_PAY_MERCHANT_* configuration.
     * Multi-merchant (per-order-type) routing — present in the legacy
     * PortalPaymentProcessor — will be ported in a follow-up phase along with
     * the authorize() implementation that needs the full merchant block.
     */
    private function buildMerchant(): MerchantDetail
    {
        return MerchantDetail::from([
            'id' => (string) ($this->app->get('ECHO_PAY_MERCHANT_ID') ?? ''),
            'key' => (string) ($this->app->get('ECHO_PAY_MERCHANT_KEY') ?? ''),
            'secretKey' => (string) ($this->app->get('ECHO_PAY_MERCHANT_SECRET') ?? ''),
        ]);
    }
}

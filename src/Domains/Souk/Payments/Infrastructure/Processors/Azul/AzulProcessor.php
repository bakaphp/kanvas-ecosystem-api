<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Azul;

use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Enums\CustomFieldEnum;
use Kanvas\Connectors\Azul\Enums\TransactionTypeEnum;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Connectors\Azul\Services\AzulService;
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

/**
 * Azul payment processor (Banco Popular, Dominican Republic).
 *
 * Capabilities:
 *   - PaymentProcessorInterface  : authorize (Sale or Hold), capture (Post), refund, void (cancels a Hold)
 *   - TokenizationProcessorInterface : tokenize via DataVault, deleteToken
 *
 * Flow modes (controlled by AZUL_USE_HOLD app config):
 *   - Sale mode (default): authorize() = immediate charge; capture() is a no-op.
 *   - Hold mode           : authorize() = pre-authorization (AUTHORIZED status);
 *                           capture()   = Post to settle funds (PAID status);
 *                           void()      = cancels the hold (CANCELLED status).
 *
 * Implements ThreeDSProcessorInterface:
 *   - startChallenge()    : sends a 3DS-enabled Sale/Hold; returns challenge URL data if Azul requires it,
 *                           or marks the payment as PAID immediately if the card is not enrolled.
 *   - finalizeChallenge() : called after the cardholder completes the browser challenge; uses
 *                           VerifyPayment to confirm the final status.
 */
class AzulProcessor implements PaymentProcessorInterface, TokenizationProcessorInterface, ThreeDSProcessorInterface
{
    protected AzulService $service;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        ?AzulService $service = null,
    ) {
        $this->service = $service ?? new AzulService($app, $company);
    }

    public function name(): string
    {
        return 'azul';
    }

    // -------------------------------------------------------------------------
    // PaymentProcessorInterface
    // -------------------------------------------------------------------------

    /**
     * Authorize the payment.
     *
     * Sale mode (default): immediate charge — payment moves to PAID.
     * Hold mode: pre-authorization — funds reserved, payment moves to AUTHORIZED.
     *            Call capture() within 7 days to settle, or void() to release.
     *
     * Mode resolution (first match wins):
     *   1. $context['use_hold'] (per-transaction override)
     *   2. AZUL_USE_HOLD app config (default for all transactions in the app)
     */
    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        $useHold = array_key_exists('use_hold', $context)
            ? (bool) $context['use_hold']
            : (bool) $this->app->get(ConfigurationEnum::AZUL_USE_HOLD->value);
        $request = $this->buildSaleRequest($payment, $order);
        $start = hrtime(true);

        try {
            $response = $useHold
                ? $this->service->hold($request)
                : $this->service->sale($request);

            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $order->set(CustomFieldEnum::AZUL_ORDER_ID->value, $response->azulOrderId);
            $order->set(CustomFieldEnum::AZUL_AUTHORIZATION_CODE->value, $response->authorizationCode);
            $order->set(CustomFieldEnum::AZUL_TICKET->value, $response->ticket);
            $order->set(CustomFieldEnum::AZUL_RRN->value, $response->rrn);
            $order->set(CustomFieldEnum::AZUL_LOT_NUMBER->value, $response->lotNumber);

            $paymentMethod = $payment->paymentMethod;
            $existingToken = $paymentMethod->getMetadata(CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value);

            if ($response->dataVaultToken && ! $existingToken) {
                $paymentMethod->metadata = array_merge(
                    $paymentMethod->metadata ?? [],
                    [CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value => $response->dataVaultToken]
                );
                $paymentMethod->save();
            }

            $payment->processor = $this->name();
            $payment->payment_intent_id = $response->azulOrderId;
            $payment->authorization_code = $response->authorizationCode;
            $payment->number = $response->ticket;

            if (! empty($response->dateTime)) {
                $payment->payment_date = Carbon::createFromFormat('YmdHis', $response->dateTime)->toDateString();
            }

            $responseData = [
                'data' => [
                    'azul_order_id' => $response->azulOrderId,
                    'authorization_code' => $response->authorizationCode,
                    'response_message' => $response->responseMessage,
                    'response_code' => $response->responseCode,
                    'iso_code' => $response->isoCode,
                    'ticket' => $response->ticket,
                    'rrn' => $response->rrn,
                    'lot_number' => $response->lotNumber,
                    'datetime' => $response->dateTime,
                    'masked_card_number' => $response->maskedCardNumber,
                ],
            ];

            if ($useHold) {
                // Funds reserved — not yet captured. Order payment_status stays unchanged.
                $payment->status = PaymentStatusEnum::AUTHORIZED->value;
                $payment->addMetadata($responseData);
                $payment->save();
                $paymentStatus = PaymentStatusEnum::AUTHORIZED;
            } else {
                $payment->markAsPaid($responseData);
                $paymentStatus = PaymentStatusEnum::PAID;
            }

            $payment->addLog($useHold ? 'hold_success' : 'authorize_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'azul_order_id' => $response->azulOrderId,
                'authorization_code' => $response->authorizationCode,
                'amount' => $order->getTotalAmount(),
                'response_time_ms' => $responseTimeMs,
                'request' => $this->sanitizeRequest($request->toArray()),
                'response' => $response->toArray(),
            ]);

            return new AuthorizeResult(
                success: true,
                message: $response->responseMessage,
                transactionId: $response->azulOrderId,
                paymentStatus: $paymentStatus,
                raw: $response->toArray(),
            );
        } catch (AzulException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addMetadata(['data' => ['error' => $e->getMessage(), 'azul_error_body' => $e->getErrorBody()]]);

            $payment->addLog('authorize_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'response_time_ms' => $responseTimeMs,
                'request' => $this->sanitizeRequest($request->toArray()),
            ]);

            $errorBody = $e->getErrorBody();
            $detail = $errorBody['ErrorDescription'] ?? $errorBody['error_description'] ?? null;
            $message = $e->getMessage() . ($detail ? ' | ' . $detail : '');

            return new AuthorizeResult(
                success: false,
                message: $message,
                transactionId: '',
                paymentStatus: PaymentStatusEnum::FAILED,
                raw: $errorBody,
            );
        }
    }

    /**
     * Post (capture) a previously held transaction.
     * No-op when the payment was charged immediately (Sale) — Azul already settled those funds.
     * In Hold mode, settles the reserved funds (Post transaction). Must be called within 7 days of Hold.
     * Amount may be equal, less, or up to 15% greater than the original Hold amount.
     */
    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        $azulOrderId = (string) ($payment->payment_intent_id ?? '');

        // If the payment was not put on hold, funds were already captured at authorize time.
        if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return new CaptureResult(
                success: true,
                message: 'Payment was not pre-authorized (Hold); no separate capture step needed.',
                transactionId: $azulOrderId,
            );
        }

        if (empty($azulOrderId)) {
            return new CaptureResult(
                success: false,
                message: 'AzulOrderId not found on payment. Cannot capture.',
                transactionId: '',
            );
        }

        $captureAmount = $amount ?? $order->getTotalAmount();
        $request = $this->buildCaptureRequest($azulOrderId, $captureAmount, $order);
        $start = hrtime(true);

        try {
            $response = $this->service->capture($request);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->authorization_code = $response->authorizationCode;
            $payment->number = $response->ticket;

            if (! empty($response->dateTime)) {
                $payment->payment_date = Carbon::createFromFormat('YmdHis', $response->dateTime)->toDateString();
            }

            $payment->markAsPaid([
                'data' => [
                    'capture_azul_order_id' => $response->azulOrderId,
                    'authorization_code' => $response->authorizationCode,
                    'response_message' => $response->responseMessage,
                    'response_code' => $response->responseCode,
                    'iso_code' => $response->isoCode,
                    'ticket' => $response->ticket,
                    'rrn' => $response->rrn,
                    'lot_number' => $response->lotNumber,
                    'datetime' => $response->dateTime,
                ],
            ]);

            $payment->addLog('capture_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'hold_azul_order_id' => $azulOrderId,
                'capture_azul_order_id' => $response->azulOrderId,
                'amount' => $captureAmount,
                'response_time_ms' => $responseTimeMs,
                'request' => $request->toArray(),
                'response' => $response->toArray(),
            ]);

            return new CaptureResult(
                success: true,
                message: $response->responseMessage,
                transactionId: $response->azulOrderId,
                raw: $response->toArray(),
            );
        } catch (AzulException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->addLog('capture_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'response_time_ms' => $responseTimeMs,
                'request' => $request->toArray(),
            ]);

            return new CaptureResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->getErrorBody(),
            );
        }
    }

    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        $azulOrderId = (string) ($payment->payment_intent_id ?? '');

        if (empty($azulOrderId)) {
            return new RefundResult(
                success: false,
                message: 'AzulOrderId not found on payment. Cannot process refund.',
                transactionId: '',
            );
        }

        $refundAmount = $amount ?? $order->getTotalAmount();
        $request = $this->buildRefundRequest($azulOrderId, $refundAmount, $order);
        $start = hrtime(true);

        try {
            $response = $this->service->refund($request);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::REVERSED->value]);
            $payment->addMetadata([
                'data' => [
                    'refund_azul_order_id' => $response->azulOrderId,
                    'refund_ticket' => $response->ticket,
                    'response_message' => $response->responseMessage,
                ],
            ]);

            $payment->addLog('refund_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'original_azul_order_id' => $azulOrderId,
                'refund_azul_order_id' => $response->azulOrderId,
                'response_time_ms' => $responseTimeMs,
                'request' => $request->toArray(),
                'response' => $response->toArray(),
            ]);

            return new RefundResult(
                success: true,
                message: $response->responseMessage,
                transactionId: $response->azulOrderId,
                raw: $response->toArray(),
            );
        } catch (AzulException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->addLog('refund_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'response_time_ms' => $responseTimeMs,
                'request' => $request->toArray(),
            ]);

            return new RefundResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->getErrorBody(),
            );
        }
    }

    /**
     * Void a transaction, releasing the reserved or charged funds back to the cardholder.
     * - Hold (AUTHORIZED): no time limit.
     * - Sale or Post (PAID): within 20 minutes of approval only (Azul enforces this window).
     */
    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        $azulOrderId = (string) ($payment->payment_intent_id ?? '');

        if (empty($azulOrderId)) {
            return new VoidResult(
                success: false,
                message: 'AzulOrderId not found on payment. Cannot void.',
                transactionId: '',
            );
        }

        $request = $this->buildVoidRequest($azulOrderId);
        $start = hrtime(true);

        try {
            $response = $this->service->void($request);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::CANCELLED->value]);
            $payment->addMetadata([
                'data' => [
                    'void_azul_order_id' => $response->azulOrderId,
                    'void_ticket' => $response->ticket,
                    'response_message' => $response->responseMessage,
                ],
            ]);

            $payment->addLog('void_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'original_azul_order_id' => $azulOrderId,
                'void_azul_order_id' => $response->azulOrderId,
                'response_time_ms' => $responseTimeMs,
                'request' => $request->toArray(),
                'response' => $response->toArray(),
            ]);

            return new VoidResult(
                success: true,
                message: $response->responseMessage,
                transactionId: $response->azulOrderId,
                raw: $response->toArray(),
            );
        } catch (AzulException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->addLog('void_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'response_time_ms' => $responseTimeMs,
                'request' => $request->toArray(),
            ]);

            return new VoidResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->getErrorBody(),
            );
        }
    }

    /**
     * Verify a payment's current status via Azul's VerifyPayment endpoint.
     * Use this to reconcile payments when a sale response was lost or ambiguous.
     * If Azul confirms the payment was approved and the local status is not yet PAID,
     * the payment and order are marked as paid automatically.
     */
    public function verify(Payments $payment, Order $order): VerifyResult
    {
        $customOrderId = (string) $order->id;

        if (empty($customOrderId)) {
            return new VerifyResult(
                success: false,
                message: 'Order ID is required for VerifyPayment.',
                transactionId: '',
                isoCode: '',
                responseCode: '',
            );
        }

        try {
            $response = $this->service->verifyPayment($customOrderId);

            $responseData = [
                'data' => [
                    'azul_order_id' => $response->azulOrderId,
                    'authorization_code' => $response->authorizationCode,
                    'response_message' => $response->responseMessage,
                    'response_code' => $response->responseCode,
                    'iso_code' => $response->isoCode,
                    'ticket' => $response->ticket,
                    'rrn' => $response->rrn,
                ],
            ];

            if ($response->isoCode === '00' && $payment->status !== PaymentStatusEnum::PAID->value) {
                $payment->markAsPaid($responseData);
                $order->markAsPaid();
            }

            $payment->addLog('verify_payment', $responseData);

            return new VerifyResult(
                success: true,
                message: $response->responseMessage,
                transactionId: $response->azulOrderId,
                isoCode: $response->isoCode,
                responseCode: $response->responseCode,
                raw: $response->toArray(),
            );
        } catch (AzulException $e) {
            $payment->addLog('verify_payment_failed', [
                'error' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
            ]);

            return new VerifyResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                isoCode: '',
                responseCode: '',
                raw: $e->getErrorBody(),
            );
        }
    }

    // -------------------------------------------------------------------------
    // ThreeDSProcessorInterface
    // -------------------------------------------------------------------------

    /**
     * Initiate a 3DS-enabled payment.
     *
     * - If the card is NOT enrolled in 3DS, Azul approves immediately (IsoCode=00)
     *   and the payment is marked PAID — no challenge needed.
     * - If a challenge IS required, Azul returns the ACS URL and PAReq.
     *   The payment is set to PENDING_AUTHORIZATION and the caller must
     *   redirect the cardholder to `data['acs_url']` with `data['pa_req']`.
     *
     * Context keys:
     *   - use_hold (bool)                     : use Hold instead of Sale (default: AZUL_USE_HOLD config)
     *   - requestor_challenge_indicator (string): Azul challenge preference (default: '01' = no preference)
     *   - card_holder_info (array)             : optional CardHolderInfo block (billing/shipping/contact)
     */
    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        $useHold = array_key_exists('use_hold', $context)
            ? (bool) $context['use_hold']
            : (bool) $this->app->get(ConfigurationEnum::AZUL_USE_HOLD->value);

        $request = $this->build3DSSaleRequest($payment, $order, $context);
        $start   = hrtime(true);

        try {
            $response = $useHold
                ? $this->service->hold3DS($request)
                : $this->service->sale3DS($request);

            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            // Store the AzulOrderId immediately so finalizeChallenge / verify can use it
            $payment->payment_intent_id  = $response->azulOrderId;
            $payment->authorization_code = $response->authorizationCode;

            if ($response->isApproved()) {
                // Card not enrolled — direct approval, no challenge needed
                $responseData = [
                    'data' => [
                        'azul_order_id'      => $response->azulOrderId,
                        'authorization_code' => $response->authorizationCode,
                        'response_message'   => $response->responseMessage,
                        'iso_code'           => $response->isoCode,
                    ],
                ];

                if (! empty($response->dateTime)) {
                    $payment->payment_date = Carbon::createFromFormat('YmdHis', $response->dateTime)->toDateString();
                }

                $payment->number = $response->ticket;
                $payment->markAsPaid($responseData);

                $payment->addLog('3ds_direct_approval', [
                    'processor'      => $this->name(),
                    'order_id'       => $order->id,
                    'azul_order_id'  => $response->azulOrderId,
                    'response_time_ms' => $responseTimeMs,
                ]);

                return new ThreeDSResult(
                    success: true,
                    message: $response->responseMessage,
                    status: PaymentStatusEnum::PAID->value,
                    data: ['azul_order_id' => $response->azulOrderId],
                    raw: $response->toArray(),
                );
            }

            // 3DS challenge required
            $payment->update(['status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value]);

            $payment->addLog('3ds_challenge_initiated', [
                'processor'      => $this->name(),
                'order_id'       => $order->id,
                'azul_order_id'  => $response->azulOrderId,
                'acs_url'        => $response->acsUrl,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: true,
                message: '3DS challenge required — redirect cardholder to acs_url',
                status: PaymentStatusEnum::PENDING_AUTHORIZATION->value,
                data: [
                    'azul_order_id'    => $response->azulOrderId,
                    'acs_url'          => $response->acsUrl,
                    'pa_req'           => $response->paReq,
                    'three_ds_trans_id' => $response->threeDSTransId,
                ],
                raw: $response->toArray(),
            );
        } catch (AzulException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addLog('3ds_challenge_failed', [
                'processor'      => $this->name(),
                'order_id'       => $order->id,
                'error'          => $e->getMessage(),
                'error_body'     => $e->getErrorBody(),
                'response_time_ms' => $responseTimeMs,
            ]);

            return new ThreeDSResult(
                success: false,
                message: $e->getMessage(),
                status: PaymentStatusEnum::FAILED->value,
                raw: $e->getErrorBody(),
            );
        }
    }

    /**
     * Finalize a 3DS challenge after the cardholder completes browser authentication.
     * Uses VerifyPayment to check Azul's final decision and marks the payment accordingly.
     */
    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        $verifyResult = $this->verify($payment, $order);

        if ($verifyResult->success) {
            return new ThreeDSResult(
                success: true,
                message: $verifyResult->message,
                status: PaymentStatusEnum::PAID->value,
                data: ['azul_order_id' => $verifyResult->transactionId],
                raw: $verifyResult->raw,
            );
        }

        $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        $payment->addLog('3ds_finalize_failed', [
            'processor' => $this->name(),
            'order_id'  => $order->id,
            'error'     => $verifyResult->message,
        ]);

        return new ThreeDSResult(
            success: false,
            message: $verifyResult->message ?: '3DS challenge verification failed',
            status: PaymentStatusEnum::FAILED->value,
            raw: $verifyResult->raw,
        );
    }

    // -------------------------------------------------------------------------
    // TokenizationProcessorInterface
    // -------------------------------------------------------------------------

    /**
     * Store card details in Azul DataVault via a $0 hold and return the vault token.
     * The token is saved on the PaymentMethod metadata for future charges.
     */
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        try {
            $expiration = $this->normalizeExpiration(
                $cardDetails['expiration_date'] ?? $cardDetails['expiration'] ?? ''
            );

            $response = $this->service->processDataVault(
                cardNumber: preg_replace('/\s+/', '', $cardDetails['number'] ?? ''),
                expiration: $expiration,
                cvc: $cardDetails['cvv'] ?? $cardDetails['cvc'] ?? null,
            );

            return new TokenizeResult(
                success: true,
                message: 'Card tokenized successfully.',
                token: $response->dataVaultToken ?? '',
                lastFour: substr(preg_replace('/\s+/', '', $cardDetails['number'] ?? ''), -4),
                brand: strtolower($response->brand ?: ($cardDetails['brand'] ?? '')),
                raw: array_merge($response->toArray(), [
                    CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value => $response->dataVaultToken,
                    'cardExpiration' => $expiration,
                ]),
            );
        } catch (AzulException $e) {
            return new TokenizeResult(
                success: false,
                message: $e->getMessage(),
                token: '',
                lastFour: '',
                brand: '',
                raw: $e->getErrorBody(),
            );
        }
    }

    /**
     * Convert MM/YY or MMYY expiration to YYYYMM format expected by Azul API.
     */
    private function normalizeExpiration(string $expiration): string
    {
        $clean = str_replace(['/', '-', ' '], '', $expiration);

        if (strlen($clean) === 4) {
            // MMYY → YYYYMM
            return '20' . substr($clean, 2, 2) . substr($clean, 0, 2);
        }

        // Already YYYYMM or other format — return as-is
        return $clean;
    }

    public function deleteToken(string $token): bool
    {
        try {
            $this->service->deleteDataVault($token);

            return true;
        } catch (AzulException) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCaptureRequest(string $azulOrderId, float $captureAmount, Order $order): AzulPaymentRequest
    {
        return new AzulPaymentRequest(
            channel: (string) ($this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? 'EC'),
            store: (string) ($this->app->get(ConfigurationEnum::AZUL_STORE->value) ?? ''),
            trxType: TransactionTypeEnum::POST,
            amount: $this->toCents($captureAmount),
            itbis: $this->toCents($order->getTotalTaxAmount()) ?: '000',
            customOrderId: (string) $order->id,
            azulOrderId: $azulOrderId,
        );
    }

    private function buildVoidRequest(string $azulOrderId): AzulPaymentRequest
    {
        return new AzulPaymentRequest(
            channel: (string) ($this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? 'EC'),
            store: (string) ($this->app->get(ConfigurationEnum::AZUL_STORE->value) ?? ''),
            trxType: TransactionTypeEnum::VOID,
            amount: '000',
            itbis: '000',
            azulOrderId: $azulOrderId,
        );
    }

    private function buildRefundRequest(string $azulOrderId, float $refundAmount, Order $order): AzulPaymentRequest
    {
        return new AzulPaymentRequest(
            channel: (string) ($this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? 'EC'),
            store: (string) ($this->app->get(ConfigurationEnum::AZUL_STORE->value) ?? ''),
            trxType: TransactionTypeEnum::REFUND,
            amount: $this->toCents($refundAmount),
            itbis: $this->toCents($order->getTotalTaxAmount()) ?: '000',
            customOrderId: (string) $order->id,
            azulOrderId: $azulOrderId,
        );
    }

    private function build3DSSaleRequest(Payments $payment, Order $order, array $context = []): AzulPaymentRequest
    {
        $paymentMethod  = $payment->paymentMethod;
        $dataVaultToken = $paymentMethod->getMetadata(CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value);

        $expiration = $dataVaultToken ? null : (
            $paymentMethod->getMetadata('expiration')
            ?? $this->normalizeExpiration($paymentMethod->expiration_date ?? '')
        );

        $termUrl   = (string) ($this->app->get(ConfigurationEnum::AZUL_3DS_TERM_URL->value) ?? '');
        $methodUrl = (string) ($this->app->get(ConfigurationEnum::AZUL_3DS_METHOD_URL->value) ?? '');

        $threeDSAuth = [
            'TermUrl'                      => $termUrl,
            'MethodNotificationUrl'        => $methodUrl,
            'RequestorChallengeIndicator'  => $context['requestor_challenge_indicator'] ?? '01',
        ];

        return new AzulPaymentRequest(
            channel: (string) ($this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? 'EC'),
            store: (string) ($this->app->get(ConfigurationEnum::AZUL_STORE->value) ?? ''),
            cardNumber: $dataVaultToken ? null : $paymentMethod->getMetadata('card_number'),
            expiration: $expiration ?? '',
            cvc: $dataVaultToken ? null : $paymentMethod->getMetadata('cvc'),
            posInputMode: 'E-Commerce',
            trxType: TransactionTypeEnum::SALE,
            amount: $this->toCents($order->getTotalAmount()),
            itbis: $this->toCents($order->getTotalTaxAmount()) ?: '000',
            orderNumber: (string) $order->order_number,
            customOrderId: (string) $order->id,
            dataVaultToken: $dataVaultToken,
            saveToDataVault: $dataVaultToken ? '0' : '1',
            forceNo3DS: '', // empty = enable 3DS
            threeDSAuth: $threeDSAuth,
            cardHolderInfo: $context['card_holder_info'] ?? null,
        );
    }

    private function buildSaleRequest(Payments $payment, Order $order): AzulPaymentRequest
    {
        $paymentMethod = $payment->paymentMethod;
        $dataVaultToken = $paymentMethod->getMetadata(CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value);

        // When using DataVaultToken, Azul docs say NOT to send CardNumber or Expiration.
        // Only compute expiration for raw-card (non-token) flows.
        $expiration = $dataVaultToken ? null : (
            $paymentMethod->getMetadata('expiration')
            ?? $this->normalizeExpiration($paymentMethod->expiration_date ?? '')
        );

        return new AzulPaymentRequest(
            channel: (string) ($this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? 'EC'),
            store: (string) ($this->app->get(ConfigurationEnum::AZUL_STORE->value) ?? ''),
            cardNumber: $dataVaultToken ? null : $paymentMethod->getMetadata('card_number'),
            expiration: $expiration ?? '',
            cvc: $dataVaultToken ? null : $paymentMethod->getMetadata('cvc'),
            posInputMode: 'E-Commerce',
            trxType: TransactionTypeEnum::SALE,
            amount: $this->toCents($order->getTotalAmount()),
            itbis: $this->toCents($order->getTotalTaxAmount()) ?: '000',
            orderNumber: (string) $order->order_number,
            customOrderId: (string) $order->id,
            dataVaultToken: $dataVaultToken,
            saveToDataVault: $dataVaultToken ? '0' : '1',
        );
    }

    /**
     * Remove sensitive card fields before logging per PCI DSS and Azul requirements.
     * Card numbers are masked; CVC/CVV are removed entirely.
     */
    private function sanitizeRequest(array $request): array
    {
        unset($request['CVC'], $request['CVV']);

        if (isset($request['CardNumber'])) {
            $request['CardNumber'] = '****' . substr((string) $request['CardNumber'], -4);
        }

        return $request;
    }

    private function toCents(float $amount): string
    {
        return (string) (int) round($amount * 100);
    }
}

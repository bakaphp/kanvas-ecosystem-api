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
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
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
 * Does NOT implement ThreeDSProcessorInterface — Azul has no 3DS challenge flow.
 */
class AzulProcessor implements PaymentProcessorInterface, TokenizationProcessorInterface
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
        $azulOrderId = (string) ($order->get(CustomFieldEnum::AZUL_ORDER_ID->value) ?? '');

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
                message: 'AzulOrderId not found on order. Cannot capture.',
                transactionId: '',
            );
        }

        $start = hrtime(true);

        try {
            $captureAmount = $amount ?? $order->getTotalAmount();
            $response = $this->service->post(
                azulOrderId: $azulOrderId,
                amount: $this->toCents($captureAmount),
                itbis: $this->toCents($order->getTotalTaxAmount()) ?: '000',
                customOrderId: (string) $order->id,
            );
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
        $start = hrtime(true);

        try {
            $azulOrderId = (string) ($order->get(CustomFieldEnum::AZUL_ORDER_ID->value) ?? '');

            if (empty($azulOrderId)) {
                throw new AzulException('AzulOrderId not found on order. Cannot process refund.', 0, null, []);
            }

            $refundAmount = $amount ?? $order->getTotalAmount();
            $response = $this->service->refund(
                azulOrderId: $azulOrderId,
                amount: $this->toCents($refundAmount),
                itbis: $this->toCents($order->getTotalTaxAmount()),
                customOrderId: (string) $order->id,
            );
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
     * Void a Hold or authorized transaction, releasing the reserved funds back to the cardholder.
     * Only valid for payments in AUTHORIZED status (Hold mode). For settled payments use refund().
     */
    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        $azulOrderId = (string) ($order->get(CustomFieldEnum::AZUL_ORDER_ID->value) ?? '');

        if (empty($azulOrderId)) {
            return new VoidResult(
                success: false,
                message: 'AzulOrderId not found on order. Cannot void.',
                transactionId: '',
            );
        }

        $start = hrtime(true);

        try {
            $response = $this->service->void(
                azulOrderId: $azulOrderId,
                customOrderId: (string) $order->id,
            );
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
            ]);

            return new VoidResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->getErrorBody(),
            );
        }
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

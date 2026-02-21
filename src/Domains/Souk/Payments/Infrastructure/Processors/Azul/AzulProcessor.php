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
 *   - PaymentProcessorInterface  : authorize (immediate charge), capture (no-op), refund, void (unsupported)
 *   - TokenizationProcessorInterface : tokenize via DataVault, deleteToken
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
     * Azul is synchronous: authorize = immediate charge (auth + capture in one step).
     */
    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        $request = $this->buildSaleRequest($payment, $order);
        $start = hrtime(true);

        try {
            $response = $this->service->sale($request);
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

            $payment->markAsPaid([
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
            ]);

            $payment->addLog('authorize_success', [
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
                paymentStatus: PaymentStatusEnum::PAID,
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
     * Azul captures immediately at authorize time — this is a no-op.
     */
    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        return new CaptureResult(
            success: true,
            message: 'Azul captures immediately at authorization; no separate capture step.',
            transactionId: (string) ($order->get(CustomFieldEnum::AZUL_ORDER_ID->value) ?? ''),
        );
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
     * Azul does not support void operations.
     */
    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        return new VoidResult(
            success: false,
            message: 'Azul does not support void. Use refund() instead.',
            transactionId: '',
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

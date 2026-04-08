<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\CardNet;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\CardNet\Client;
use Kanvas\Connectors\CardNet\DataTransferObject\CardDetail;
use Kanvas\Connectors\CardNet\DataTransferObject\CardNetPurchaseRequest;
use Kanvas\Connectors\CardNet\Enums\CustomFieldEnum;
use Kanvas\Connectors\CardNet\Exceptions\CardNetException;
use Kanvas\Connectors\CardNet\Services\CardNetService;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\ActivationProcessorInterface;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\ActivateResult;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

/**
 * CardNet payment processor (Dominican Republic).
 *
 * Capabilities:
 *   - PaymentProcessorInterface      : authorize (Sale or Hold), capture (Commit), refund, void
 *   - TokenizationProcessorInterface : tokenize via CardNet Secure Token, deleteToken
 *
 * Flow modes (controlled by $context['use_hold']):
 *   - Sale mode (default): authorize() = immediate charge; capture() is a no-op.
 *   - Hold mode           : authorize() = pre-authorization (AUTHORIZED status);
 *                           capture()   = Commit to settle funds (PAID status);
 *                           void()      = refund to cancel (CANCELLED status).
 */
class CardNetProcessor implements PaymentProcessorInterface, TokenizationProcessorInterface, ActivationProcessorInterface
{
    protected CardNetService $service;

    public function __construct(
        protected AppInterface $app,
        ?CardNetService $service = null,
    ) {
        $this->service = $service ?? new CardNetService(new Client($app));
    }

    public function name(): string
    {
        return 'cardnet';
    }

    /**
     * Authorize the payment.
     *
     * Sale mode (default): immediate charge — payment moves to PAID.
     * Hold mode: pre-authorization — funds reserved, payment moves to AUTHORIZED.
     *            Call capture() to settle, or void() to release.
     *
     * Mode resolution (first match wins):
     *   1. $context['use_hold'] (per-transaction override)
     */
    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        $useHold = (bool) ($context['use_hold'] ?? false);
        $request = $this->buildPurchaseRequest($payment, $order, ! $useHold);
        $start = hrtime(true);

        try {
            $response = $useHold
                ? $this->service->hold($request)
                : $this->service->purchase($request);

            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $purchaseId = $response->getPurchaseId();
            $approvalCode = $response->getApprovalCode();

            $order->set(CustomFieldEnum::PURCHASE_ID->value, $purchaseId);
            $order->set(CustomFieldEnum::AUTHORIZATION_CODE->value, $approvalCode);

            $payment->processor = $this->name();
            $payment->payment_intent_id = (string) $purchaseId;
            $payment->authorization_code = $approvalCode;

            $responseData = [
                'data' => [
                    'purchase_id' => $purchaseId,
                    'approval_code' => $approvalCode,
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            ];

            if ($useHold) {
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
                'purchase_id' => $purchaseId,
                'approval_code' => $approvalCode,
                'amount' => $order->getTotalAmount(),
                'response_time_ms' => $responseTimeMs,
                'response' => $response->getResponseMessage(),
            ]);

            return new AuthorizeResult(
                success: true,
                message: $response->getResponseMessage(),
                transactionId: (string) $purchaseId,
                paymentStatus: $paymentStatus,
                raw: [
                    'purchase_id' => $purchaseId,
                    'approval_code' => $approvalCode,
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            );
        } catch (CardNetException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            $payment->addMetadata(['data' => ['error' => $e->getMessage(), 'cardnet_error_body' => $e->responseBody]]);

            $payment->addLog('authorize_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->responseBody,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new AuthorizeResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                paymentStatus: PaymentStatusEnum::FAILED,
                raw: $e->responseBody,
            );
        }
    }

    /**
     * Commit (capture) a previously held transaction.
     * No-op when the payment was charged immediately (Sale).
     */
    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        $purchaseId = (int) ($payment->payment_intent_id ?? 0);

        if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return new CaptureResult(
                success: true,
                message: 'Payment was not pre-authorized (Hold); no separate capture step needed.',
                transactionId: (string) $purchaseId,
            );
        }

        if ($purchaseId === 0) {
            return new CaptureResult(
                success: false,
                message: 'CardNet PurchaseId not found on payment. Cannot capture.',
                transactionId: '',
            );
        }

        $start = hrtime(true);

        try {
            $response = $this->service->commit($purchaseId);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->authorization_code = $response->getApprovalCode();

            $payment->markAsPaid([
                'data' => [
                    'capture_purchase_id' => $response->getPurchaseId(),
                    'approval_code' => $response->getApprovalCode(),
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            ]);

            $payment->addLog('capture_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'hold_purchase_id' => $purchaseId,
                'capture_purchase_id' => $response->getPurchaseId(),
                'amount' => $amount ?? $order->getTotalAmount(),
                'response_time_ms' => $responseTimeMs,
                'response' => $response->getResponseMessage(),
            ]);

            return new CaptureResult(
                success: true,
                message: $response->getResponseMessage(),
                transactionId: (string) $response->getPurchaseId(),
                raw: [
                    'purchase_id' => $response->getPurchaseId(),
                    'approval_code' => $response->getApprovalCode(),
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            );
        } catch (CardNetException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->addLog('capture_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->responseBody,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new CaptureResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->responseBody,
            );
        }
    }

    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        $purchaseId = (int) ($payment->payment_intent_id ?? 0);

        if ($purchaseId === 0) {
            return new RefundResult(
                success: false,
                message: 'CardNet PurchaseId not found on payment. Cannot process refund.',
                transactionId: '',
            );
        }

        $start = hrtime(true);

        try {
            $response = $this->service->refund($purchaseId);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::REVERSED->value]);
            $payment->addMetadata([
                'data' => [
                    'refund_purchase_id' => $response->getPurchaseId(),
                    'response_message' => $response->getResponseMessage(),
                    'response_code' => $response->getResponseCode(),
                ],
            ]);

            $payment->addLog('refund_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'original_purchase_id' => $purchaseId,
                'refund_purchase_id' => $response->getPurchaseId(),
                'response_time_ms' => $responseTimeMs,
                'response' => $response->getResponseMessage(),
            ]);

            return new RefundResult(
                success: true,
                message: $response->getResponseMessage(),
                transactionId: (string) $response->getPurchaseId(),
                raw: [
                    'purchase_id' => $response->getPurchaseId(),
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            );
        } catch (CardNetException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->addLog('refund_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->responseBody,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new RefundResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->responseBody,
            );
        }
    }

    /**
     * Void a transaction by issuing a refund — CardNet uses the same refund endpoint for voids.
     */
    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        $purchaseId = (int) ($payment->payment_intent_id ?? 0);

        if ($purchaseId === 0) {
            return new VoidResult(
                success: false,
                message: 'CardNet PurchaseId not found on payment. Cannot void.',
                transactionId: '',
            );
        }

        $start = hrtime(true);

        try {
            $response = $this->service->refund($purchaseId);
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->update(['status' => PaymentStatusEnum::CANCELLED->value]);
            $payment->addMetadata([
                'data' => [
                    'void_purchase_id' => $response->getPurchaseId(),
                    'response_message' => $response->getResponseMessage(),
                    'response_code' => $response->getResponseCode(),
                ],
            ]);

            $payment->addLog('void_success', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'original_purchase_id' => $purchaseId,
                'void_purchase_id' => $response->getPurchaseId(),
                'response_time_ms' => $responseTimeMs,
                'response' => $response->getResponseMessage(),
            ]);

            return new VoidResult(
                success: true,
                message: $response->getResponseMessage(),
                transactionId: (string) $response->getPurchaseId(),
                raw: [
                    'purchase_id' => $response->getPurchaseId(),
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            );
        } catch (CardNetException $e) {
            $responseTimeMs = (int) round((hrtime(true) - $start) / 1e6);

            $payment->addLog('void_failed', [
                'processor' => $this->name(),
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'error_body' => $e->responseBody,
                'response_time_ms' => $responseTimeMs,
            ]);

            return new VoidResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $e->responseBody,
            );
        }
    }

    /**
     * Verify the current status of a payment via CardNet's getPurchase endpoint.
     * If CardNet confirms the payment is approved and the local status is not yet PAID,
     * the payment and order are marked as paid automatically.
     */
    public function verify(Payments $payment, Order $order): VerifyResult
    {
        $purchaseId = (int) ($payment->payment_intent_id ?? 0);

        if ($purchaseId === 0) {
            return new VerifyResult(
                success: false,
                message: 'CardNet PurchaseId not found on payment. Cannot verify.',
                transactionId: '',
                isoCode: '',
                responseCode: '',
            );
        }

        try {
            $response = $this->service->getPurchase($purchaseId);

            $responseData = [
                'data' => [
                    'purchase_id' => $response->getPurchaseId(),
                    'approval_code' => $response->getApprovalCode(),
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            ];

            if ($response->isApproved() && $payment->status !== PaymentStatusEnum::PAID->value) {
                $payment->markAsPaid($responseData);
                $order->markAsPaid($payment->user);
            }

            $payment->addLog('verify_payment', $responseData);

            return new VerifyResult(
                success: true,
                message: $response->getResponseMessage(),
                transactionId: (string) $response->getPurchaseId(),
                isoCode: $response->getResponseCode(),
                responseCode: $response->getResponseCode(),
                raw: [
                    'purchase_id' => $response->getPurchaseId(),
                    'approval_code' => $response->getApprovalCode(),
                    'response_code' => $response->getResponseCode(),
                    'response_message' => $response->getResponseMessage(),
                    'transaction_id' => $response->getTransactionId(),
                    'status' => $response->getStatus(),
                ],
            );
        } catch (CardNetException $e) {
            $payment->addLog('verify_payment_failed', [
                'error' => $e->getMessage(),
                'error_body' => $e->responseBody,
            ]);

            return new VerifyResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                isoCode: '',
                responseCode: '',
                raw: $e->responseBody,
            );
        }
    }

    /**
     * Create a CardNet customer and tokenize card details via the Secure Token API.
     * The resulting commerce token is stored in the TokenizeResult for persistence
     * on the PaymentMethod metadata.
     */
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        try {
            $customerId = $this->getOrCreateCustomerId($user, $cardDetails);

            $cardNumber = preg_replace('/\s+/', '', $cardDetails['number'] ?? '');

            $cardDetail = new CardDetail(
                email: $user->email,
                pan: $cardNumber,
                cvv: $cardDetails['cvv'] ?? $cardDetails['cvc'] ?? '',
                expiration: $this->normalizeExpiration(
                    $cardDetails['expiration_date'] ?? $cardDetails['expiration'] ?? ''
                ),
                titular: trim(
                    ($cardDetails['firstname'] ?? '') . ' ' . ($cardDetails['lastname'] ?? '')
                ) ?: ($cardDetails['cardholder_name'] ?? ''),
                customerId: $customerId,
            );

            $tokenResponse = $this->service->tokenizeDirect($cardDetail);

            $token = (string) ($tokenResponse['TokenId'] ?? '');

            if (empty($token)) {
                $errorMessage = $tokenResponse['Error']['Message'] ?? 'CardNet tokenization failed — no token returned.';

                return new TokenizeResult(
                    success: false,
                    message: $errorMessage,
                    token: '',
                    lastFour: '',
                    brand: '',
                    raw: $tokenResponse,
                );
            }

            return new TokenizeResult(
                success: true,
                message: 'Card tokenized successfully.',
                token: $token,
                lastFour: substr($cardNumber, -4),
                brand: strtolower($tokenResponse['Brand'] ?? $cardDetails['brand'] ?? ''),
                raw: array_merge($tokenResponse, [
                    CustomFieldEnum::CUSTOMER_ID->value => $customerId,
                    CustomFieldEnum::TOKEN->value => $token,
                ]),
            );
        } catch (CardNetException $e) {
            return new TokenizeResult(
                success: false,
                message: $e->getMessage(),
                token: '',
                lastFour: '',
                brand: '',
                raw: $e->responseBody,
            );
        }
    }

    public function deleteToken(string $token): bool
    {
        $paymentMethod = PaymentMethods::where('stripe_card_id', $token)
            ->whereNotNull('metadata')
            ->first();

        if (! $paymentMethod) {
            return true;
        }

        $customerId = (int) ($paymentMethod->getMetadata(CustomFieldEnum::CUSTOMER_ID->value) ?? 0);
        $paymentProfileId = (int) ($paymentMethod->getMetadata(CustomFieldEnum::PAYMENT_PROFILE_ID->value) ?? 0);

        if ($customerId === 0 || $paymentProfileId === 0) {
            return true;
        }

        try {
            $this->service->deletePaymentProfile($customerId, $paymentProfileId);

            return true;
        } catch (CardNetException) {
            return false;
        }
    }

    public function activatePaymentMethod(PaymentMethods $paymentMethod, string $activationCode): ActivateResult
    {
        $customerId = (int) ($paymentMethod->getMetadata(CustomFieldEnum::CUSTOMER_ID->value) ?? 0);
        $token = $paymentMethod->stripe_card_id;

        if ($customerId === 0 || empty($token)) {
            return new ActivateResult(
                success: false,
                message: 'Missing CardNet customer ID or token in payment method metadata.',
            );
        }

        try {
            $response = $this->service->activatePaymentProfile($customerId, $token, $activationCode);

            $paymentProfileId = (int) ($response['PaymentProfileId'] ?? 0);

            if ($paymentProfileId > 0) {
                $metadata = $paymentMethod->metadata ?? [];
                $metadata[CustomFieldEnum::PAYMENT_PROFILE_ID->value] = $paymentProfileId;
                $paymentMethod->update(['metadata' => $metadata]);
            }

            return new ActivateResult(
                success: true,
                message: 'Payment method activated successfully.',
                raw: $response,
            );
        } catch (CardNetException $e) {
            return new ActivateResult(
                success: false,
                message: $e->getMessage(),
                raw: $e->responseBody,
            );
        }
    }

    private function getOrCreateCustomerId(UserInterface $user, array $cardDetails): int
    {
        $customerId = (int) ($user->get(CustomFieldEnum::CUSTOMER_ID->value) ?? 0);

        if ($customerId > 0) {
            return $customerId;
        }

        $customerResponse = $this->service->createCustomer(
            email: $user->email,
            firstName: $cardDetails['firstname'] ?? null,
            lastName: $cardDetails['lastname'] ?? null,
            phoneNumber: $cardDetails['phone'] ?? null,
        );

        $customerId = (int) ($customerResponse['CustomerId'] ?? 0);

        if ($customerId === 0) {
            throw new CardNetException('CardNet customer creation failed — no CustomerId returned.');
        }

        $user->set(CustomFieldEnum::CUSTOMER_ID->value, $customerId);

        return $customerId;
    }

    private function buildPurchaseRequest(Payments $payment, Order $order, bool $capture = true): CardNetPurchaseRequest
    {
        $paymentMethod = $payment->paymentMethod;
        $trxToken = (string) ($paymentMethod->getMetadata(CustomFieldEnum::TOKEN->value) ?? '');

        return new CardNetPurchaseRequest(
            trxToken: $trxToken,
            order: (string) $order->id,
            amount: $this->toCardNetAmount($order->getTotalAmount()),
            currency: (string) ($order->currency ?? 'DOP'),
            invoice: (string) ($order->order_number ?? $order->id),
            capture: $capture,
        );
    }

    /**
     * CardNet expects amounts as integers in the smallest currency unit (centavos).
     */
    private function toCardNetAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Normalize expiration to MM/YY format expected by CardNet Secure Token API.
     * Accepts MM/YY, MMYY, MM/YYYY, MMYYYY, or YYYYMM.
     */
    private function normalizeExpiration(string $expiration): string
    {
        $clean = str_replace(['/', '-', ' '], '', $expiration);

        if (strlen($clean) === 4) {
            // MMYY → MM/YY
            return substr($clean, 0, 2) . '/' . substr($clean, 2, 2);
        }

        if (strlen($clean) === 6) {
            // Could be MMYYYY or YYYYMM
            $first2 = (int) substr($clean, 0, 2);
            if ($first2 >= 1 && $first2 <= 12) {
                // MMYYYY → MM/YY
                return substr($clean, 0, 2) . '/' . substr($clean, 4, 2);
            }
            // YYYYMM → MM/YY
            return substr($clean, 4, 2) . '/' . substr($clean, 2, 2);
        }

        return $expiration;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Stripe;

use DomainException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\CurrencyEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Stripe payment processor for Souk Order charges.
 *
 * Charges flow through Stripe PaymentIntents, never the Cashier/subscription code:
 *   - authorize() : create (or retrieve) a PaymentIntent and map its status to the Payment.
 *   - capture()   : settle a manual-capture PaymentIntent; no-op when already captured.
 *   - refund()    : create a Stripe Refund and append a payment_refunds row.
 *   - void()      : cancel an uncaptured PaymentIntent; refusing once funds are captured.
 *   - verify()    : reconcile local status against Stripe (used by the reconciliation cron).
 *
 * 3DS (ThreeDSProcessorInterface): when a PaymentIntent lands in `requires_action`,
 * authorize() returns the `client_secret` in AuthorizeResult.data so the browser can run
 * the challenge; finalizeChallenge() re-reads the intent and advances the Payment.
 *
 * This class MUST NOT import StripeCheckoutService, StripePlanService,
 * StripePaymentLinkService, or any Cashier class — credentials and the client are
 * Company-scoped and injected by PaymentProcessorServiceProvider.
 */
final class StripeProcessor implements PaymentProcessorInterface, ThreeDSProcessorInterface
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected StripeClient $stripe,
    ) {
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        // Already settled/closed — never create a fresh charge (the webhook may have landed first).
        if ($this->isTerminal((string) $payment->status)) {
            return new AuthorizeResult(
                success: $payment->status === PaymentStatusEnum::PAID->value,
                message: 'Payment already in terminal state: ' . $payment->status,
                transactionId: (string) ($payment->payment_intent_id ?? ''),
                paymentStatus: PaymentStatusEnum::from((string) $payment->status),
            );
        }

        // Idempotency: if a PaymentIntent already exists, reflect it instead of double-charging.
        if (! empty($payment->payment_intent_id)) {
            try {
                $intent = $this->stripe->paymentIntents->retrieve((string) $payment->payment_intent_id);

                return $this->toAuthorizeResult($payment, $order, $intent, 'authorize_retrieved');
            } catch (ApiErrorException) {
                // Intent vanished at Stripe — fall through and create a fresh one.
            }
        }

        // off_session defaults to true here — authorize() is the saved-card / merchant-initiated
        // entry. For a customer-present 3DS charge use startChallenge() (off_session = false).
        try {
            $intent = $this->createIntent($payment, $order, $context);
        } catch (ApiErrorException $e) {
            $this->markFailedFromException($payment, $order, $e, 'authorize_failed');

            return new AuthorizeResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                paymentStatus: PaymentStatusEnum::FAILED,
                raw: $this->errorBody($e),
            );
        }

        return $this->toAuthorizeResult($payment, $order, $intent, 'authorize');
    }

    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        $intentId = (string) ($payment->payment_intent_id ?? '');

        if ($intentId === '') {
            return new CaptureResult(
                success: false,
                message: 'No PaymentIntent on payment. Cannot capture.',
                transactionId: '',
            );
        }

        try {
            $intent = $this->stripe->paymentIntents->retrieve($intentId);

            // Automatic-capture intents settle at authorize time — nothing to do.
            if ($intent->status === 'succeeded') {
                return new CaptureResult(
                    success: true,
                    message: 'PaymentIntent already captured; no separate capture needed.',
                    transactionId: $intentId,
                );
            }

            $params = [];
            if ($amount !== null) {
                $params['amount_to_capture'] = $this->toCents($amount);
            }

            $captured = $this->stripe->paymentIntents->capture($intentId, $params);
            $this->syncPaymentFromIntent($payment, $order, $captured, 'capture');

            return new CaptureResult(
                success: $captured->status === 'succeeded',
                message: 'PaymentIntent status: ' . $captured->status,
                transactionId: $captured->id,
                raw: $captured->toArray(),
            );
        } catch (ApiErrorException $e) {
            $payment->addLog('capture_failed', [
                'processor' => $this->name(),
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);

            return new CaptureResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $this->errorBody($e),
            );
        }
    }

    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        // Only a captured (PAID) payment can be refunded — AUTHORIZED should be voided, and a
        // REVERSED/CANCELLED payment has nothing left to refund. A partially-refunded payment
        // stays PAID until fully refunded, so it correctly passes this guard.
        if ($payment->status !== PaymentStatusEnum::PAID->value) {
            return new RefundResult(
                success: false,
                message: 'Only paid payments can be refunded; current status: ' . $payment->status,
                transactionId: '',
            );
        }

        $intentId = (string) ($payment->payment_intent_id ?? '');
        $chargeId = (string) ($payment->getMetadata('stripe_charge_id') ?? $payment->authorization_code ?? '');

        if ($intentId === '' && $chargeId === '') {
            return new RefundResult(
                success: false,
                message: 'No PaymentIntent or charge on payment. Cannot refund.',
                transactionId: '',
            );
        }

        // Default to the amount actually charged (Payment.amount), not the order total — they can
        // diverge (partial captures, order edits after capture). Refund what Stripe took.
        $refundAmount = $amount ?? (float) $payment->amount;
        $amountCents = $this->toCents($refundAmount);

        $params = ['amount' => $amountCents];
        // Stripe prefers refunding by charge; fall back to the PaymentIntent when the charge id is unknown.
        if ($chargeId !== '') {
            $params['charge'] = $chargeId;
        } else {
            $params['payment_intent'] = $intentId;
        }

        $refund = new PaymentRefund();
        $refund->apps_id = $this->app->getId();
        $refund->companies_id = $this->company->getId();
        $refund->payments_id = $payment->getId();
        $refund->users_id = $payment->users_id;
        $refund->amount = $refundAmount;
        $refund->currency = $payment->currency ?? $order->currency;
        $refund->reason = $context['reason'] ?? null;
        $refund->status = RefundStatusEnum::PENDING->value;
        $refund->saveOrFail();

        try {
            $stripeRefund = $this->stripe->refunds->create(
                $params,
                ['idempotency_key' => 'refund:' . $payment->getId() . ':' . $amountCents],
            );
        } catch (ApiErrorException $e) {
            $refund->markAsFailed(['error' => $e->getMessage()]);

            $payment->addLog('refund_failed', [
                'processor' => $this->name(),
                'order_id' => $order->getId(),
                'refund_id' => $refund->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RefundResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $this->errorBody($e),
            );
        }

        $refund->processor_refund_id = $stripeRefund->id;
        $refund->save();
        // markAsCompleted flips the Payment to REVERSED once cumulative refunds reach the total
        // and cascades to the Order via SyncPayablePaymentStatusAction.
        $refund->markAsCompleted([
            'stripe_refund_id' => $stripeRefund->id,
            'stripe_status' => $stripeRefund->status,
        ]);

        $payment->addLog('refund_success', [
            'processor' => $this->name(),
            'order_id' => $order->getId(),
            'refund_id' => $refund->getId(),
            'stripe_refund_id' => $stripeRefund->id,
            'amount' => $refundAmount,
        ]);

        return new RefundResult(
            success: true,
            message: 'Refund ' . $stripeRefund->status,
            transactionId: $stripeRefund->id,
            raw: $stripeRefund->toArray(),
        );
    }

    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        $intentId = (string) ($payment->payment_intent_id ?? '');

        if ($intentId === '') {
            return new VoidResult(
                success: false,
                message: 'No PaymentIntent on payment. Cannot void.',
                transactionId: '',
            );
        }

        try {
            $intent = $this->stripe->paymentIntents->retrieve($intentId);

            if ($intent->status === 'succeeded') {
                throw new DomainException('Cannot void a captured Stripe payment — use refund.');
            }

            $canceled = $this->stripe->paymentIntents->cancel($intentId);

            $payment->status = PaymentStatusEnum::CANCELLED->value;
            $this->clearClientSecret($payment);
            $payment->addMetadata(['data' => ['stripe_status' => $canceled->status]]);
            $payment->save();

            $payment->addLog('void_success', [
                'processor' => $this->name(),
                'order_id' => $order->getId(),
                'payment_intent_id' => $canceled->id,
            ]);

            return new VoidResult(
                success: true,
                message: 'PaymentIntent ' . $canceled->status,
                transactionId: $canceled->id,
                raw: $canceled->toArray(),
            );
        } catch (ApiErrorException $e) {
            $payment->addLog('void_failed', [
                'processor' => $this->name(),
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);

            return new VoidResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                raw: $this->errorBody($e),
            );
        }
    }

    public function verify(Payments $payment, Order $order): VerifyResult
    {
        $intentId = (string) ($payment->payment_intent_id ?? '');

        if ($intentId === '') {
            return new VerifyResult(
                success: false,
                message: 'No PaymentIntent on payment. Cannot verify.',
                transactionId: '',
                isoCode: '',
                responseCode: '',
            );
        }

        try {
            $intent = $this->stripe->paymentIntents->retrieve($intentId);
        } catch (ApiErrorException $e) {
            return new VerifyResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
                isoCode: '',
                responseCode: '',
                raw: $this->errorBody($e),
            );
        }

        $status = $this->syncPaymentFromIntent($payment, $order, $intent, 'verify');

        return new VerifyResult(
            success: true,
            message: 'PaymentIntent status: ' . $intent->status,
            transactionId: $intent->id,
            isoCode: (string) $intent->status,
            responseCode: $status->value,
            raw: $intent->toArray(),
        );
    }

    /**
     * Customer-present entry point. Creates and confirms the PaymentIntent on-session
     * (off_session = false) so Stripe runs the 3DS challenge inline and returns a
     * client_secret for stripe.handleNextAction(). Charges that don't need a challenge
     * settle straight to PAID here. Re-callable: an existing PaymentIntent is reflected,
     * not recreated.
     */
    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        if ($this->isTerminal((string) $payment->status)) {
            return new ThreeDSResult(
                success: $payment->status === PaymentStatusEnum::PAID->value,
                message: 'Payment already in terminal state: ' . $payment->status,
                status: (string) $payment->status,
            );
        }

        $intentId = (string) ($payment->payment_intent_id ?? '');

        try {
            $intent = $intentId === ''
                ? $this->createIntent($payment, $order, ['off_session' => false] + $context)
                : $this->stripe->paymentIntents->retrieve($intentId);
        } catch (ApiErrorException $e) {
            $this->markFailedFromException($payment, $order, $e, '3ds_start_failed');

            return new ThreeDSResult(
                success: false,
                message: $e->getMessage(),
                status: PaymentStatusEnum::FAILED->value,
                raw: $this->errorBody($e),
            );
        }

        $status = $this->syncPaymentFromIntent($payment, $order, $intent, '3ds_start');

        $data = [];
        $message = 'PaymentIntent status: ' . $intent->status;
        if ($status === PaymentStatusEnum::PENDING_AUTHORIZATION) {
            $data = [
                'client_secret' => (string) $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
            $message = '3DS challenge required — run handleNextAction with client_secret';
        }

        return new ThreeDSResult(
            success: $status !== PaymentStatusEnum::FAILED,
            message: $message,
            status: $status->value,
            data: $data,
            raw: $intent->toArray(),
        );
    }

    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        $intentId = (string) ($payment->payment_intent_id ?? '');

        if ($intentId === '') {
            return new ThreeDSResult(
                success: false,
                message: 'Missing PaymentIntent — challenge was never initiated for this payment.',
                status: PaymentStatusEnum::FAILED->value,
            );
        }

        // Idempotent: client and webhook may both finalize — once terminal, report without re-writing.
        if ($this->isTerminal((string) $payment->status)) {
            return new ThreeDSResult(
                success: $payment->status === PaymentStatusEnum::PAID->value,
                message: 'Payment already finalized.',
                status: (string) $payment->status,
            );
        }

        try {
            $intent = $this->stripe->paymentIntents->retrieve($intentId);
        } catch (ApiErrorException $e) {
            return new ThreeDSResult(
                success: false,
                message: $e->getMessage(),
                status: PaymentStatusEnum::FAILED->value,
                raw: $this->errorBody($e),
            );
        }

        $status = $this->syncPaymentFromIntent($payment, $order, $intent, '3ds_finalize');

        $data = [];
        if ($status === PaymentStatusEnum::PENDING_AUTHORIZATION) {
            $data['client_secret'] = (string) $intent->client_secret;
        }

        return new ThreeDSResult(
            success: $status !== PaymentStatusEnum::FAILED,
            message: 'PaymentIntent status: ' . $intent->status,
            status: $status->value,
            data: $data,
            raw: $intent->toArray(),
        );
    }

    /**
     * Map the PaymentIntent status onto the Payment and persist the transition.
     * Terminal Payments (PAID / REVERSED / CANCELLED) short-circuit so repeated calls
     * from the API path and the webhook stay idempotent.
     */
    private function syncPaymentFromIntent(Payments $payment, Order $order, PaymentIntent $intent, string $event): PaymentStatusEnum
    {
        if ($this->isTerminal((string) $payment->status)) {
            return PaymentStatusEnum::from((string) $payment->status);
        }

        $status = $this->mapIntentStatus((string) $intent->status);
        $chargeId = $this->extractChargeId($intent);

        $payment->processor = $this->name();
        $payment->payment_intent_id = $intent->id;

        $data = array_filter([
            'stripe_payment_intent_id' => $intent->id,
            'stripe_status' => $intent->status,
            'stripe_charge_id' => $chargeId,
        ], fn ($value) => $value !== null);

        switch ($status) {
            case PaymentStatusEnum::PAID:
                if ($chargeId !== null) {
                    $payment->authorization_code = $chargeId;
                }
                $this->clearClientSecret($payment);
                $payment->markAsPaid(['data' => $data]);

                break;

            case PaymentStatusEnum::AUTHORIZED:
                $this->clearClientSecret($payment);
                $payment->status = PaymentStatusEnum::AUTHORIZED->value;
                $payment->addMetadata(['data' => $data]);
                $payment->save();

                break;

            case PaymentStatusEnum::PENDING_AUTHORIZATION:
                $data['stripe_client_secret'] = $intent->client_secret;
                $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
                $payment->addMetadata(['data' => $data]);
                $payment->save();

                break;

            case PaymentStatusEnum::FAILED:
                $error = $intent->last_payment_error->message ?? null;
                if ($error !== null) {
                    $data['stripe_error'] = $error;
                }
                $this->clearClientSecret($payment);
                $payment->status = PaymentStatusEnum::FAILED->value;
                $payment->addMetadata(['data' => $data]);
                $payment->save();

                break;

            default:
                $payment->status = PaymentStatusEnum::PROCESSING->value;
                $payment->addMetadata(['data' => $data]);
                $payment->save();
        }

        $payment->addLog($event, [
            'processor' => $this->name(),
            'order_id' => $order->getId(),
            'payment_intent_id' => $intent->id,
            'stripe_status' => $intent->status,
            'payment_status' => $status->value,
        ]);

        return $status;
    }

    private function toAuthorizeResult(Payments $payment, Order $order, PaymentIntent $intent, string $event): AuthorizeResult
    {
        $status = $this->syncPaymentFromIntent($payment, $order, $intent, $event);

        $data = [];
        if ($status === PaymentStatusEnum::PENDING_AUTHORIZATION) {
            $data = [
                'client_secret' => (string) $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
        }

        return new AuthorizeResult(
            success: $status !== PaymentStatusEnum::FAILED,
            message: 'PaymentIntent status: ' . $intent->status,
            transactionId: $intent->id,
            paymentStatus: $status,
            raw: $intent->toArray(),
            data: $data,
        );
    }

    /**
     * Build and confirm a PaymentIntent for this order. Shared by authorize() (off_session
     * defaults true — saved card / MIT) and startChallenge() (off_session forced false —
     * customer present). Throws ApiErrorException; callers map that to a FAILED payment.
     */
    private function createIntent(Payments $payment, Order $order, array $context): PaymentIntent
    {
        $this->assertSupportedCurrency($order);

        $paymentMethod = $payment->paymentMethod;
        $customerId = (string) ($paymentMethod?->getMetadata('stripe_customer_id') ?? '');
        $stripePaymentMethodId = (string) (
            $paymentMethod?->getMetadata('stripe_payment_method_id')
            ?? $paymentMethod?->stripe_card_id
            ?? ''
        );

        // Stripe disables its idempotency guard on a null key, so retries would double-charge.
        $idempotencyKey = ! empty($payment->idempotency_key)
            ? (string) $payment->idempotency_key
            : 'pi:payment:' . $payment->getId();

        $params = [
            'amount' => $this->toCents($order->getTotalAmount()),
            'currency' => strtolower((string) $order->currency),
            'confirm' => true,
            'off_session' => $context['off_session'] ?? true,
            'capture_method' => ($context['manual_capture'] ?? false) ? 'manual' : 'automatic',
            // Card-only processor: accounts with automatic payment methods enabled reject
            // confirm-without-return_url. allow_redirects 'never' still permits 3DS (handleNextAction).
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            'metadata' => [
                'kanvas_app_id' => $this->app->getId(),
                'kanvas_company_id' => $this->company->getId(),
                'kanvas_payment_id' => $payment->getId(),
                'kanvas_order_id' => $order->getId(),
            ],
        ];

        if ($customerId !== '') {
            $params['customer'] = $customerId;
        }
        if ($stripePaymentMethodId !== '') {
            $params['payment_method'] = $stripePaymentMethodId;
        }

        return $this->stripe->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    private function markFailedFromException(Payments $payment, Order $order, ApiErrorException $e, string $event): void
    {
        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->processor = $this->name();
        $this->clearClientSecret($payment);
        $payment->addMetadata(['data' => ['stripe_error' => $e->getMessage()]]);
        $payment->save();

        $payment->addLog($event, [
            'processor' => $this->name(),
            'order_id' => $order->getId(),
            'error' => $e->getMessage(),
        ]);
    }

    private function mapIntentStatus(string $stripeStatus): PaymentStatusEnum
    {
        return match ($stripeStatus) {
            'succeeded' => PaymentStatusEnum::PAID,
            'requires_capture' => PaymentStatusEnum::AUTHORIZED,
            'requires_action' => PaymentStatusEnum::PENDING_AUTHORIZATION,
            'requires_payment_method', 'canceled' => PaymentStatusEnum::FAILED,
            default => PaymentStatusEnum::PROCESSING,
        };
    }

    private function extractChargeId(PaymentIntent $intent): ?string
    {
        if (empty($intent->latest_charge)) {
            return null;
        }

        // latest_charge is a charge id when unexpanded, or a Charge object when expanded.
        return is_string($intent->latest_charge)
            ? $intent->latest_charge
            : ($intent->latest_charge->id ?? null);
    }

    /**
     * The client_secret grants action on the PaymentIntent — drop it once the Payment
     * reaches a state where no further challenge is expected.
     */
    private function clearClientSecret(Payments $payment): void
    {
        $metadata = $payment->metadata ?? [];

        if (isset($metadata['data']['stripe_client_secret'])) {
            unset($metadata['data']['stripe_client_secret']);
            $payment->metadata = $metadata;
        }
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, [
            PaymentStatusEnum::PAID->value,
            PaymentStatusEnum::REVERSED->value,
            PaymentStatusEnum::CANCELLED->value,
        ], true);
    }

    private function assertSupportedCurrency(Order $order): void
    {
        $currency = strtoupper(trim((string) $order->currency));

        if (CurrencyEnum::tryFrom($currency) === null) {
            throw new ValidationException(
                'Stripe does not support currency ' . $currency . ' for order ' . $order->getId()
            );
        }
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function errorBody(ApiErrorException $e): array
    {
        return [
            'error' => $e->getMessage(),
            'stripe_code' => $e->getStripeCode(),
            'http_status' => $e->getHttpStatus(),
        ];
    }
}

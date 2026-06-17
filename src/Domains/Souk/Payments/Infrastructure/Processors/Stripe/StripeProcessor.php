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
        if ($this->isTerminal((string) $payment->status)) {
            return new AuthorizeResult(
                success: $payment->status === PaymentStatusEnum::PAID->value,
                message: 'Payment already in terminal state: ' . $payment->status,
                transactionId: (string) ($payment->payment_intent_id ?? ''),
                paymentStatus: PaymentStatusEnum::from((string) $payment->status),
            );
        }

        if (! empty($payment->payment_intent_id)) {
            try {
                $intent = $this->stripe->paymentIntents->retrieve((string) $payment->payment_intent_id);

                return $this->toAuthorizeResult($payment, $order, $intent, 'authorize_retrieved');
            } catch (ApiErrorException) {
            }
        }

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

        $refundAmount = $amount ?? (float) $payment->amount;
        $amountCents = $this->toCents($refundAmount);

        $params = ['amount' => $amountCents];
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
            // allow_redirects 'never' lets a card-only confirm skip return_url while still allowing 3DS.
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

        return is_string($intent->latest_charge)
            ? $intent->latest_charge
            : ($intent->latest_charge->id ?? null);
    }

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

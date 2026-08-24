<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Souk Order/Payment-oriented Stripe webhook (distinct from the Cashier/subscription
 * StripePaymentIntentWebhookJob). The webhook is the source of truth: every transition is
 * idempotent, so a delivery that races the synchronous API path is a no-op.
 *
 * One ReceiverWebhook is registered per Company, so $this->receiver->company is authoritative;
 * the kanvas_company_id in the PaymentIntent metadata is only a cross-check.
 *
 * Signature is verified against the raw request body stored by ProcessWebhookAttemptAction
 * (receiver_webhook_calls.raw_payload) — re-serialising the decoded payload would change the
 * bytes and always fail Stripe's HMAC.
 */
#[WorkflowAction(
    name: 'Stripe Order Payment Webhook',
    description: 'Receiver for Stripe payment events on a Souk ORDER: moves the order and its payment through '
        . 'their states as Stripe reports success, failure, or that more authentication is needed. The '
        . 'webhook is the source of truth and every transition is idempotent, so a re-delivery or a '
        . 'race with the checkout call is a no-op. One receiver per company. Distinct from the Cashier '
        . 'receiver, which handles app subscriptions.',
    integration: IntegrationsEnum::STRIPE,
)]
class StripeOrderPaymentWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $secret = (string) ($this->receiver->company->get(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value) ?? '');

        if ($secret === '') {
            throw new ValidationException('Stripe webhook secret not configured for company');
        }

        $event = Webhook::constructEvent(
            (string) $this->webhookRequest->raw_payload,
            $this->signatureHeader(),
            $secret,
        );

        $object = $event->data->object;

        return match ($event->type) {
            'payment_intent.succeeded' => $this->handleSucceeded($object),
            'payment_intent.payment_failed' => $this->handleFailed($object),
            'payment_intent.requires_action' => $this->handleRequiresAction($object),
            'charge.refunded' => $this->handleChargeRefunded($object),
            'refund.created', 'refund.updated' => $this->handleRefundEvent($object),
            default => ['message' => "Ignored event {$event->type}"],
        };
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = (string) ($receiver->company->get(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value) ?? '');
        $signature = $request->header('Stripe-Signature');

        if ($secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        try {
            Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException | UnexpectedValueException) {
            return false;
        }

        return true;
    }

    private function handleSucceeded(StripeObject $intent): array
    {
        $payment = $this->findPaymentByIntent((string) $intent->id);

        if (! $payment) {
            return ['message' => 'payment not found', 'payment_intent_id' => $intent->id];
        }

        if (! $this->companyMatches($intent)) {
            return ['message' => 'company mismatch', 'payment_intent_id' => $intent->id];
        }

        if ($this->isTerminal($payment)) {
            return ['message' => 'payment already terminal', 'status' => $payment->status];
        }

        $chargeId = $this->extractChargeId($intent);

        if ($chargeId !== null) {
            $payment->authorization_code = $chargeId;
        }

        $this->clearClientSecret($payment);
        $payment->markAsPaid(['data' => array_filter([
            'stripe_payment_intent_id' => $intent->id,
            'stripe_status' => $intent->status,
            'stripe_charge_id' => $chargeId,
        ], fn ($value) => $value !== null)]);

        return ['message' => 'payment marked paid', 'payment_id' => $payment->getId()];
    }

    private function handleFailed(StripeObject $intent): array
    {
        $payment = $this->findPaymentByIntent((string) $intent->id);

        if (! $payment) {
            return ['message' => 'payment not found', 'payment_intent_id' => $intent->id];
        }

        if (! $this->companyMatches($intent)) {
            return ['message' => 'company mismatch', 'payment_intent_id' => $intent->id];
        }

        if ($this->isTerminal($payment)) {
            return ['message' => 'payment already terminal', 'status' => $payment->status];
        }

        $error = $intent->last_payment_error->message ?? null;

        $this->clearClientSecret($payment);
        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->addMetadata(['data' => array_filter([
            'stripe_payment_intent_id' => $intent->id,
            'stripe_status' => $intent->status,
            'stripe_error' => $error,
        ], fn ($value) => $value !== null)]);
        $payment->save();

        return ['message' => 'payment marked failed', 'payment_id' => $payment->getId()];
    }

    private function handleRequiresAction(StripeObject $intent): array
    {
        $payment = $this->findPaymentByIntent((string) $intent->id);

        if (! $payment) {
            return ['message' => 'payment not found', 'payment_intent_id' => $intent->id];
        }

        if (! $this->companyMatches($intent)) {
            return ['message' => 'company mismatch', 'payment_intent_id' => $intent->id];
        }

        if ($this->isTerminal($payment)) {
            return ['message' => 'payment already terminal', 'status' => $payment->status];
        }

        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $payment->addMetadata(['data' => [
            'stripe_payment_intent_id' => $intent->id,
            'stripe_client_secret' => $intent->client_secret,
        ]]);
        $payment->save();

        return ['message' => 'payment requires action', 'payment_id' => $payment->getId()];
    }

    private function handleChargeRefunded(StripeObject $charge): array
    {
        $refunds = $charge->refunds->data ?? [];
        $latest = $refunds[0] ?? null;

        if (! $latest) {
            return ['message' => 'no refund on charge', 'charge_id' => $charge->id];
        }

        return $this->recordRefund(
            (string) $charge->id,
            (string) $latest->id,
            (int) $latest->amount,
            (string) ($latest->status ?? 'succeeded'),
        );
    }

    private function handleRefundEvent(StripeObject $refund): array
    {
        $chargeId = is_string($refund->charge) ? $refund->charge : (string) ($refund->charge->id ?? '');

        if ($chargeId === '') {
            return ['message' => 'refund has no charge', 'refund_id' => $refund->id];
        }

        return $this->recordRefund(
            $chargeId,
            (string) $refund->id,
            (int) $refund->amount,
            (string) ($refund->status ?? 'succeeded'),
        );
    }

    private function recordRefund(string $chargeId, string $refundId, int $amountCents, string $status): array
    {
        $payment = $this->findPaymentByCharge($chargeId);

        if (! $payment) {
            return ['message' => 'payment not found', 'charge_id' => $chargeId];
        }

        $alreadyRecorded = PaymentRefund::where('payments_id', $payment->getId())
            ->where('processor_refund_id', $refundId)
            ->exists();

        if ($alreadyRecorded) {
            return ['message' => 'refund already recorded', 'refund_id' => $refundId];
        }

        $refund = new PaymentRefund();
        $refund->apps_id = $this->receiver->app->getId();
        $refund->companies_id = $this->receiver->company->getId();
        $refund->payments_id = $payment->getId();
        $refund->users_id = $payment->users_id;
        $refund->amount = $amountCents / 100;
        $refund->currency = $payment->currency;
        $refund->status = RefundStatusEnum::PENDING->value;
        $refund->processor_refund_id = $refundId;

        try {
            $refund->saveOrFail();
        } catch (QueryException $e) {
            // Unique (payments_id, processor_refund_id): a concurrent delivery already inserted it.
            if (PaymentRefund::where('payments_id', $payment->getId())->where('processor_refund_id', $refundId)->exists()) {
                return ['message' => 'refund already recorded', 'refund_id' => $refundId];
            }

            throw $e;
        }

        // markAsCompleted flips the Payment to REVERSED once cumulative refunds reach the total.
        $refund->markAsCompleted([
            'stripe_refund_id' => $refundId,
            'stripe_status' => $status,
        ]);

        return ['message' => 'refund recorded', 'refund_id' => $refundId, 'payment_id' => $payment->getId()];
    }

    private function findPaymentByIntent(string $intentId): ?Payments
    {
        return Payments::where('apps_id', $this->receiver->app->getId())
            ->where('companies_id', $this->receiver->company->getId())
            ->where('payment_intent_id', $intentId)
            ->first();
    }

    private function findPaymentByCharge(string $chargeId): ?Payments
    {
        return Payments::where('apps_id', $this->receiver->app->getId())
            ->where('companies_id', $this->receiver->company->getId())
            ->where('authorization_code', $chargeId)
            ->first();
    }

    private function companyMatches(StripeObject $intent): bool
    {
        $metadataCompanyId = $intent->metadata->kanvas_company_id ?? null;

        // Older intents may predate the metadata; only reject on an explicit mismatch.
        if ($metadataCompanyId === null || $metadataCompanyId === '') {
            return true;
        }

        return (string) $metadataCompanyId === (string) $this->receiver->company->getId();
    }

    private function extractChargeId(StripeObject $intent): ?string
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

    private function isTerminal(Payments $payment): bool
    {
        return in_array($payment->status, [
            PaymentStatusEnum::PAID->value,
            PaymentStatusEnum::REVERSED->value,
            PaymentStatusEnum::CANCELLED->value,
        ], true);
    }

    private function signatureHeader(): string
    {
        $headers = $this->webhookRequest->headers ?? [];
        $value = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';

        return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
    }
}

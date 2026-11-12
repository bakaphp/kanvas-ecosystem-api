<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Baka\Support\Money;
use Illuminate\Http\Request;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\MessageNotificationTextAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement as ModelsEngagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Webhooks\Concerns\VerifiesStripeSignature;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Payments\Actions\CreateScribePaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use NotificationChannels\Expo\ExpoChannel;
use Override;

#[WorkflowAction(
    name: 'Stripe Payment Link Webhook',
    description: 'Receiver for a paid Stripe payment link: records the purchase against its engagement and '
        . 'PUSH-NOTIFIES the people involved. It contacts users, so it is not a silent bookkeeping '
        . 'step.',
    integration: IntegrationsEnum::STRIPE,
)]
class StripePaymentLinkWebhookJob extends ProcessWebhookJob
{
    use VerifiesStripeSignature;

    /**
     * Stripe signs each endpoint with its own secret, so it lives on the receiver rather than the
     * company (where the order-payment receiver keeps its own). Receivers wired before the secret
     * existed carry none and keep being trusted, so setting it is what turns verification on.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = (string) ($receiver->configuration[ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value] ?? '');

        return $secret === '' || self::hasValidStripeSignature($request, $secret);
    }

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $eventType = $payload['type'] ?? null;

        return match ($eventType) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($payload),
            default => [
                'message' => 'Event type not handled: ' . $eventType,
                'response' => null,
            ],
        };
    }

    /**
     * @todo move to use commerce to register purchase
     */
    protected function handleCheckoutCompleted(array $payload): array
    {
        $session = $payload['data']['object'];

        if (($session['payment_status'] ?? null) !== 'paid') {
            return [
                'message' => 'Checkout session not paid: ' . ($session['payment_status'] ?? 'null'),
                'response' => null,
            ];
        }

        if (! empty($session['metadata']['invoice_id'])) {
            return $this->handleInvoicePaid($session);
        }

        // A null value drops the value filter from the lookup and would match any deposit message.
        $paymentLinkId = (string) ($session['payment_link'] ?? '');

        if ($paymentLinkId === '') {
            return [
                'message' => 'Checkout session has no payment link',
                'response' => null,
            ];
        }

        $company = $this->resolveCompany($session);

        if (! $company) {
            return [
                'message' => 'No lead found for shared Stripe account session: ' . ($session['metadata']['leads_id'] ?? 'null'),
                'response' => null,
            ];
        }

        $message = Message::getByCustomFieldTransactionSafe(
            CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value,
            $paymentLinkId,
            $company
        );

        if (! $message) {
            return [
                'message' => 'No message found for payment link id: ' . $paymentLinkId,
                'response' => null,
            ];
        }

        $engagement = ModelsEngagement::fromApp($this->receiver->app)
            ->fromCompany($company)
            ->where('message_id', $message->getId())
            ->first();

        if (! $engagement) {
            return [
                'message' => 'No engagement found for message id: ' . $message->getId(),
                'response' => null,
            ];
        }

        // Only the lead get-deposit flow is handled here; a document payment completes through Scribe.
        if ($engagement->hasNonLeadReference()) {
            return [
                'message' => 'Engagement ' . $engagement->getId() . ' references a ' . $engagement->entity_type . ', not a lead',
                'response' => null,
            ];
        }

        // Stripe redelivers until it sees a 2xx, and every delivery would otherwise submit again and re-notify.
        $processedSessionId = $message->get(CustomFieldEnum::STRIPE_CHECKOUT_SESSION_ID->value);

        if ($processedSessionId !== null) {
            return [
                'message' => 'Checkout session already processed: ' . $processedSessionId,
                'response' => null,
            ];
        }

        $lead = $engagement->lead;
        $owner = $lead->owner ?? $engagement->user;
        $action = $message->message['verb'] ?? null;

        $firstEngagement = EngagementRepository::findEngagementForLeadBuilder(
            $lead,
            $action,
            'sent',
            'DESC'
        )->first();

        $taskId = $lead->get('check_list_status') ?? $lead->company->get('default_checklist_id');

        $engagementData = new Engagement(
            app: $lead->app,
            company: $lead->company,
            user: $owner,
            lead: $lead,
            action: $action,
            requestId: (string) ($firstEngagement ?? $engagement)->entity_uuid,
            source: 'stripe',
            status: ActionStatusEnum::SUBMITTED,
            people: $lead->people,
            receiverId: $lead->receiver?->getId(),
            taskId: is_array($taskId) && isset($taskId['activeTaskListId']) ? (int)$taskId['activeTaskListId'] : (int)$taskId,
            via: 'webhook',
            data: $payload,
        );

        $submittedEngagement = new CreateEngagementAction($engagementData)->execute();
        $submittedMessage = Message::getById($submittedEngagement->message_id, $this->receiver->app);
        $submittedMessage->parent_id = $message->getId();
        $submittedMessage->saveOrFail();
        $message->set(CustomFieldEnum::STRIPE_CHECKOUT_SESSION_ID->value, (string) $session['id']);

        $notificationMessage = new MessageNotificationTextAction($submittedEngagement, $submittedMessage)->notificationText();
        $notification = new Blank(
            'new-push-default',
            [
                'title' => $engagement->companyAction->action->name,
                'message' => $notificationMessage,
                'destination_id' => $engagement->leads_id,
                'destination_slug' => $message->slug,
                'destination_type' => 'ENGAGEMENT',
                'destination_event' => 'CREATED',
                'notification_type' => null,
                'user_id' => $engagement->user_id,
            ],
            [OneSignalNotificationChannel::class, ExpoChannel::class],
            $engagement,
        );
        $owner->notify($notification);

        return [
            'message' => 'Checkout session completed processed successfully.',
            'response' => $submittedEngagement,
            'first_engagement' => $firstEngagement?->getId(),
            'last_engagement' => $submittedEngagement->getId(),
        ];
    }

    /**
     * A Scribe invoice link (StripePaymentLinkService::generatePaymentLinkFromInvoice). The session id is
     * the payment's external id, so a redelivery finds the payment it already booked and stops there.
     */
    protected function handleInvoicePaid(array $session): array
    {
        $sessionId = (string) $session['id'];
        $metadata = $session['metadata'];
        $sharedAccount = (bool) ($this->receiver->configuration[ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value] ?? false);
        $companyId = $sharedAccount ? (int) ($metadata['company_id'] ?? 0) : $this->receiver->company->getId();

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->where('id', (int) $metadata['invoice_id'])
            ->where('apps_id', $this->receiver->app->getId())
            ->where('companies_id', $companyId)
            ->notDeleted()
            ->first();

        if ($invoice === null) {
            return [
                'message' => 'No invoice ' . $metadata['invoice_id'] . ' for this receiver',
                'response' => null,
            ];
        }

        $alreadyBooked = Payment::query()
            ->where('apps_id', $invoice->apps_id)
            ->where('companies_id', $invoice->companies_id)
            ->where('source', 'stripe')
            ->where('external_id', $sessionId)
            ->first();

        if ($alreadyBooked !== null) {
            return [
                'message' => 'Checkout session already processed: ' . $sessionId,
                'response' => $alreadyBooked->getId(),
            ];
        }

        $amount = (float) Money::fromMinorUnits((int) $session['amount_total'], $invoice->currency);

        $payment = new CreateScribePaymentAction(
            app: $invoice->app,
            company: $invoice->company,
            amountNative: $amount,
            currency: $invoice->currency,
            direction: PaymentDirectionEnum::INBOUND,
            method: PaymentMethodEnum::STRIPE,
            user: $this->receiver->user,
            reference: (string) ($session['payment_intent'] ?? $sessionId),
            notes: 'Stripe payment link for invoice ' . $invoice->invoice_number,
            source: 'stripe',
            externalId: $sessionId,
            metadata: ['payment_link' => $session['payment_link'] ?? null],
        )->execute();

        new AllocateInvoicePaymentAction(
            invoice: $invoice,
            amountNative: $amount,
            method: PaymentMethodEnum::STRIPE,
            payment: $payment,
            reference: $payment->reference,
            user: $this->receiver->user,
            source: 'stripe',
        )->execute();

        return [
            'message' => 'Invoice ' . $invoice->invoice_number . ' paid through Stripe',
            'response' => $payment->getId(),
        ];
    }

    /**
     * A receiver on the app-level Stripe key serves every company on it, so the paying lead
     * (copied from the payment link's metadata onto the session) decides the company.
     */
    protected function resolveCompany(array $session): ?Companies
    {
        if (! ($this->receiver->configuration[ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value] ?? false)) {
            return $this->receiver->company;
        }

        return Lead::query()
            ->fromApp($this->receiver->app)
            ->where('id', (int) ($session['metadata']['leads_id'] ?? 0))
            ->first()
            ?->company;
    }
}

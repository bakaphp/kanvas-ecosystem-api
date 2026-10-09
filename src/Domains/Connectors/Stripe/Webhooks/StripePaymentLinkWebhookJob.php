<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Illuminate\Http\Request;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\MessageNotificationTextAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement as ModelsEngagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use NotificationChannels\Expo\ExpoChannel;
use Override;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

#[WorkflowAction(
    name: 'Stripe Payment Link Webhook',
    description: 'Receiver for a paid Stripe payment link: records the purchase against its engagement and '
        . 'PUSH-NOTIFIES the people involved. It contacts users, so it is not a silent bookkeeping '
        . 'step.',
    integration: IntegrationsEnum::STRIPE,
)]
class StripePaymentLinkWebhookJob extends ProcessWebhookJob
{
    /**
     * Stripe signs each endpoint with its own secret, so it lives on the receiver rather than the
     * company (where the order-payment receiver keeps its own). Receivers wired before the secret
     * existed carry none and keep being trusted, so setting it is what turns verification on.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = (string) ($receiver->configuration[ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value] ?? '');

        if ($secret === '') {
            return true;
        }

        $signature = $request->header('Stripe-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        try {
            Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return false;
        }

        return true;
    }

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $eventType = $payload['type'] ?? null;

        // Handle different event types
        return match ($eventType) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($payload),
            // 'checkout.session.async_payment_succeeded' => $this->handleAsyncPaymentSucceeded($payload),
            // 'checkout.session.async_payment_failed' => $this->handleAsyncPaymentFailed($payload),
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

        // A null value drops the value filter from the lookup and would match any deposit message.
        $paymentLinkId = (string) ($session['payment_link'] ?? '');

        if ($paymentLinkId === '') {
            return [
                'message' => 'Checkout session has no payment link',
                'response' => null,
            ];
        }

        $message = Message::getByCustomFieldTransactionSafe(
            'stripe_payment_link_id',
            $paymentLinkId,
            $this->webhookRequest->receiverWebhook->company
        );

        if (! $message) {
            return [
                'message' => 'No message found for payment link id: ' . $paymentLinkId,
                'response' => null,
            ];
        }

        $engagement = ModelsEngagement::fromApp($this->webhookRequest->receiverWebhook->app)
            ->fromCompany($this->webhookRequest->receiverWebhook->company)
            ->where('message_id', $message->getId())
            ->first();

        if (! $engagement) {
            return [
                'message' => 'No engagement found for message id: ' . $message->getId(),
                'response' => null,
            ];
        }

        // Stripe redelivers until it sees a 2xx, and every delivery would otherwise submit again and re-notify.
        if ($message->get('stripe_checkout_session_id') !== null) {
            return [
                'message' => 'Checkout session already processed: ' . $message->get('stripe_checkout_session_id'),
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
        );

        $taskId = $lead->get('check_list_status') ?? $lead->company->get('default_checklist_id');

        // Create engagement data manually
        $engagementData = new Engagement(
            app: $lead->app,
            company: $lead->company,
            user: $owner,
            lead: $lead,
            action: $action,
            requestId: $firstEngagement->exists() ? (string)$firstEngagement->first()->entity_uuid : $engagement->entity_uuid,
            source: 'stripe',
            status: ActionStatusEnum::SUBMITTED,
            people: $lead->people,
            receiverId: $lead->receiver?->getId(),
            taskId: is_array($taskId) && isset($taskId['activeTaskListId']) ? (int)$taskId['activeTaskListId'] : (int)$taskId,
            via: 'webhook',
            data: $payload,
        );

        $submittedEngagement = new CreateEngagementAction($engagementData)->execute();
        $submittedMessage = Message::getById($submittedEngagement->message_id, $this->webhookRequest->receiverWebhook->app);
        $submittedMessage->parent_id = $message->getId();
        $submittedMessage->saveOrFail();
        $message->set('stripe_checkout_session_id', (string) $session['id']);

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
            'first_engagement' => $firstEngagement->first()?->getId(),
            'last_engagement' => $submittedEngagement->getId(),
        ];
    }
}

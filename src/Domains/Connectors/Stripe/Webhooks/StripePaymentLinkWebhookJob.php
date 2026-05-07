<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\MessageNotificationTextAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement as ModelsEngagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use NotificationChannels\Expo\ExpoChannel;
use Override;

class StripePaymentLinkWebhookJob extends ProcessWebhookJob
{
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
        $metadata = $session['metadata'] ?? [];
        //$messageId = $metadata['message_id'] ?? null;
        //$leadId = $metadata['leads_id'] ?? null;

        $message = Message::getByCustomField(
            'stripe_payment_link_id',
            $session['payment_link'] ?? null,
            $this->webhookRequest->receiverWebhook->company
        );

        if (! $message) {
            return [
                'message' => 'No message found for payment link id: ' . ($session['payment_link'] ?? 'null'),
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

        $lead = $engagement->lead;
        //$message = Message::getById((int)$messageId, $this->webhookRequest->receiverWebhook->app);
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
            user: $lead->owner,
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
            //formType: $metadata['form_type'] ?? null,
            //extraField: $metadata['extra_field'] ?? [],
            //extraData: [],
            //channelId: $metadata['channel_id'] ?? null,
        );

        $submittedEngagement = new CreateEngagementAction($engagementData)->execute();
        $submittedMessage = Message::getById($submittedEngagement->message_id, $this->webhookRequest->receiverWebhook->app);
        $submittedMessage->parent_id = $message->getId();
        $submittedMessage->saveOrFail();

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
        $lead->owner->notify($notification);

        return [
            'message' => 'Checkout session completed processed successfully.',
            'response' => $submittedEngagement,
            'first_engagement' => $firstEngagement->first()?->getId(),
            'last_engagement' => $submittedEngagement->getId(),
        ];
    }
}

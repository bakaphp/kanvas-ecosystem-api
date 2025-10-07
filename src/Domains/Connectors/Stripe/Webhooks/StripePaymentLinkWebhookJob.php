<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
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
        $messageId = $metadata['message_id'] ?? null;
        $leadId = $metadata['leads_id'] ?? null;

        $lead = Lead::getById((int)$leadId, $this->webhookRequest->receiverWebhook->app);
        $message = Message::getById((int)$messageId, $this->webhookRequest->receiverWebhook->app);
        $action = $message->message['verb'] ?? null;

        $firstEngagement = EngagementRepository::findEngagementForLeadBuilder(
            $lead,
            $action,
            'sent',
            'DESC'
        );

        // Create engagement data manually
        $engagementData = new Engagement(
            app: $lead->app,
            company: $lead->company,
            user: $lead->owner,
            lead: $lead,
            action: $action,
            requestId: $firstEngagement->exists() ? (string)$firstEngagement->first()->request_id : null,
            source: 'stripe',
            status: ActionStatusEnum::SUBMITTED,
            people: $lead->people,
            receiverId: $lead->receiver?->getId(),
            taskId: $lead->get('check_list_status') ?? $lead->company->get('default_checklist_id'),
            via: 'webhook',
            data: $payload,
            //formType: $metadata['form_type'] ?? null,
            //extraField: $metadata['extra_field'] ?? [],
            //extraData: [],
            //channelId: $metadata['channel_id'] ?? null,
        );

        $submittedEngagement = new CreateEngagementAction($engagementData)->execute();

        return [
            'message' => 'Checkout session completed processed successfully.',
            'response' => $submittedEngagement,
            'first_engagement' => $firstEngagement->first()?->getId(),
            'last_engagement' => $submittedEngagement->getId(),
        ];
    }
}

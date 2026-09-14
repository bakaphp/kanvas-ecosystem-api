<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Jobs;

use Kanvas\Connectors\Calendly\Actions\ProcessCalendlyInviteeAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'Calendly Booking Webhook',
    description: 'Receiver for Calendly: turns a booking or cancellation notification into the matching Kanvas '
        . 'records for the invitee. Inbound only — it reacts to what someone booked and does not create '
        . 'or move anything in Calendly.',
    integration: IntegrationsEnum::CALENDLY,
)]
class ProcessCalendlyWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $eventType = $payload['event'] ?? '';

        /** @var array<string, mixed> $config */
        $config = $this->receiver->configuration ?? [];
        $allowedEvents = (array) ($config['event_types'] ?? []);

        if (! empty($allowedEvents) && ! in_array($eventType, $allowedEvents)) {
            return [
                'message' => 'Event type "' . $eventType . '" is not configured for processing.',
                'skipped' => true,
            ];
        }

        $inviteePayload = (array) ($payload['payload'] ?? []);
        $scheduledEvent = (array) ($inviteePayload['scheduled_event'] ?? []);
        $eventName = (string) ($scheduledEvent['name'] ?? '');
        $allowedEventNames = (array) ($config['event_names'] ?? []);

        if (! empty($allowedEventNames) && ! in_array($eventName, $allowedEventNames)) {
            return [
                'message' => 'Event name "' . $eventName . '" is not configured for processing.',
                'skipped' => true,
            ];
        }

        return new ProcessCalendlyInviteeAction($this->webhookRequest)->execute();
    }
}

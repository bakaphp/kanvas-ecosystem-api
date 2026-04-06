<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Jobs;

use Kanvas\Connectors\Calendly\Actions\ProcessCalendlyInviteeAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

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

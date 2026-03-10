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
        $allowedEvents = $config['event_types'] ?? [];

        if (! empty($allowedEvents) && ! in_array($eventType, $allowedEvents)) {
            return [
                'message' => 'Event type "' . $eventType . '" is not configured for processing.',
                'skipped' => true,
            ];
        }

        return new ProcessCalendlyInviteeAction($this->webhookRequest)->execute();
    }
}

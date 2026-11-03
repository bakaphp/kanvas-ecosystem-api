<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Connectors\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Social\Messages\Models\MessageAttempt;
use Kanvas\Social\Messages\Models\MessageDeliveryEvent;

/**
 * Files one Mailgun event against the attempt it belongs to and moves the attempt's status forward.
 * Mailgun retries a webhook it did not get a 2xx for, so the event's own id is the dedupe key.
 */
final class RecordEmailDeliveryEventAction
{
    public function __construct(
        private readonly MessageAttempt $attempt,
        private readonly MailgunEngagementPayloadService $payload,
    ) {
    }

    /** @return array{event: MessageDeliveryEvent, created: bool, advanced: bool} */
    public function execute(DeliveryStatusEnum $status): array
    {
        $eventKey = hash(
            'sha256',
            'mailgun|' . ($this->payload->eventId() ?: json_encode($this->payload->eventData(), JSON_THROW_ON_ERROR)),
        );

        $existing = MessageDeliveryEvent::query()->where('event_key', $eventKey)->first();
        if ($existing instanceof MessageDeliveryEvent) {
            return ['event' => $existing, 'created' => false, 'advanced' => false];
        }

        $errorCode = $status->isDeliveryOutcome() || $status === DeliveryStatusEnum::TEMPORARY_FAILURE
            ? $this->payload->deliveryStatusCode()
            : null;
        $errorMessage = $errorCode !== null ? $this->payload->deliveryStatusMessage() : null;

        $advanced = $status->changesDeliveryStatus()
            && $status->canFollow(DeliveryStatusEnum::tryFromStatus($this->attempt->current_status));

        if ($advanced) {
            $this->attempt->fill([
                'current_status' => $status->value,
                'last_error_code' => $status === DeliveryStatusEnum::DELIVERED ? null : $errorCode,
                'last_error_message' => $status === DeliveryStatusEnum::DELIVERED ? null : $errorMessage,
                'terminal_at' => $status->isDeliveryOutcome() ? now() : null,
                'classification' => $status->classification(),
                'remediation_action' => $status->remediationAction(),
            ]);
        } elseif (! $status->changesDeliveryStatus()) {
            // A complaint or an unsubscribe lands on a delivered email: the status stays, the reason changes.
            $this->attempt->fill([
                'classification' => $status->classification(),
                'remediation_action' => $status->remediationAction(),
            ]);
        }
        $this->attempt->saveOrFail();

        $event = MessageDeliveryEvent::query()->create([
            'apps_id' => $this->attempt->apps_id,
            'companies_id' => $this->attempt->companies_id,
            'attempt_id' => $this->attempt->getId(),
            'provider' => $status->provider(),
            'event_key' => $eventKey,
            'source' => 'webhook',
            'status' => $status->value,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'classification' => $status->classification(),
            'remediation_action' => $status->remediationAction(),
            'payload' => $this->payload->eventData(),
            'occurred_at' => $this->payload->occurredAt(),
            'received_at' => now(),
            'processed_at' => now(),
            'processing_result' => $advanced ? 'attempt_snapshot_updated' : 'recorded_only',
        ]);

        return ['event' => $event, 'created' => true, 'advanced' => $advanced];
    }
}

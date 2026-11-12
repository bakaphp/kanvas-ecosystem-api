<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Shared by both Mailgun event receivers: the delivery one and the opened one, which stays on its own
 * URL because Mailgun keeps one URL per domain and event.
 */
final class RecordEmailEventOnDeliveryAction
{
    public function __construct(
        private readonly Delivery $delivery,
        private readonly DeliveryStatusEnum $event,
        private readonly MailgunEngagementPayloadService $payload,
    ) {
    }

    /**
     * Within the receiver's app only; the tenant comes from the delivery, since one sending domain can
     * serve several companies.
     */
    public static function findDelivery(AppInterface $app, MailgunEngagementPayloadService $payload): ?Delivery
    {
        $messageId = FileEmailDeliveryAction::normalizeMessageId($payload->messageId());

        return $messageId === '' ? null : Delivery::query()
            ->fromApp($app)
            ->where('provider', IntegrationsEnum::MAILGUN->value)
            ->where('external_id', $messageId)
            ->first();
    }

    /**
     * @return bool false when this exact event was already recorded
     */
    public function execute(): bool
    {
        $statusBefore = $this->delivery->status;
        $errorCode = $this->event->isFailure() ? $this->payload->deliveryStatusCode() : null;

        $recorded = new RecordDeliveryEventAction(
            delivery: $this->delivery,
            status: $this->event->toDeliveryStatus(),
            providerStatus: $this->event->value,
            payload: $this->payload->eventData(),
            occurredAt: $this->payload->occurredAt(),
            errorCode: $errorCode !== null ? (string) $errorCode : null,
            error: $this->event->isFailure() ? $this->payload->deliveryStatusMessage() : null,
        )->execute();

        if ($recorded && $this->delivery->status !== $statusBefore) {
            new RecordDeliveryOnMessageAction($this->delivery)->execute();
        }

        return $recorded;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Communication\Channels\Email\Mail\MailCarrier;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * One Mailgun domain event written on its delivery, whichever kind it is: a delivery event or an open.
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
     * serve several companies. Mail the Kanvas mailer relayed through Mailgun is reported on too.
     */
    public static function findDelivery(AppInterface $app, MailgunEngagementPayloadService $payload): ?Delivery
    {
        $messageId = FileEmailDeliveryAction::normalizeMessageId($payload->messageId());

        return $messageId === '' ? null : Delivery::query()
            ->fromApp($app)
            ->reportedBy([IntegrationsEnum::MAILGUN->value, MailCarrier::slug()], $messageId)
            ->first();
    }

    /**
     * @return bool false when this exact event was already recorded
     */
    public function execute(): bool
    {
        $errorCode = $this->event->isFailure() ? $this->payload->deliveryStatusCode() : null;

        return new RecordDeliveryEventAction(
            delivery: $this->delivery,
            status: $this->event->toDeliveryStatus(),
            providerStatus: $this->event->value,
            payload: $this->payload->eventData(),
            occurredAt: $this->payload->occurredAt(),
            errorCode: $errorCode !== null ? (string) $errorCode : null,
            error: $this->event->isFailure() ? $this->payload->deliveryStatusMessage() : null,
        )->execute();
    }
}

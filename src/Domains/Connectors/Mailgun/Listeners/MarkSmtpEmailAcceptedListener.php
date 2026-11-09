<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Notifications\Notification;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Throwable;

/**
 * The SMTP server took an email FileEmailDeliveryAction::sending() filed: that is Mailgun's `accepted`,
 * the same verdict a 200 from the send API gives a mailbox email. A Mailgun event that beat the queue
 * here already moved the delivery forward, and the status never moves back.
 */
final class MarkSmtpEmailAcceptedListener
{
    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;

        if ($event->channel !== 'mail' || ! $notification instanceof Notification || $notification->getMessageId() === null) {
            return;
        }

        try {
            $this->markAccepted($notification->getMessageId());
        } catch (Throwable $exception) {
            // The email is already out; a bookkeeping failure must not fail the job and send it again.
            report($exception);
        }
    }

    private function markAccepted(string $messageId): void
    {
        $delivery = Delivery::query()
            ->where('provider', IntegrationsEnum::MAILGUN->value)
            ->where('external_id', $messageId)
            ->where('status', DeliveryStatusEnum::SENDING->value)
            ->first();

        if (! $delivery instanceof Delivery) {
            return;
        }

        $recorded = new RecordDeliveryEventAction(
            delivery: $delivery,
            status: DeliveryStatusEnum::ACCEPTED,
            providerStatus: 'smtp_accepted',
            occurredAt: now(),
        )->execute();

        if (! $recorded) {
            return;
        }

        $delivery->forceFill(['accepted_at' => now()])->saveOrFail();

        new RecordDeliveryOnMessageAction($delivery)->execute();
    }
}

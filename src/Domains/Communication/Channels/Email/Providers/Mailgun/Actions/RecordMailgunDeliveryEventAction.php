<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

/**
 * A Mailgun delivery event (delivered, failed, complained, unsubscribed) for an outbound email,
 * shared by the legacy delivery receiver and the communication webhook.
 *
 * The event is matched by its Message-Id to the delivery filed at send time, and the tenant comes
 * from that delivery, not the receiver: one sending domain can serve several companies, and Mailgun
 * calls a single webhook URL per domain. An event for mail we have no delivery for (sent by another
 * system, or before this existed) is ignored rather than guessed onto a tenant.
 */
final class RecordMailgunDeliveryEventAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly MailgunEngagementPayloadService $payload
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $event = DeliveryStatusEnum::fromEvent($this->payload->event(), $this->payload->severity());

        if ($event === null) {
            return ['message' => 'Event ' . ($this->payload->event() ?: 'without a name') . ' is not a delivery event, ignored'];
        }

        $delivery = RecordEmailEventOnDeliveryAction::findDelivery($this->app, $this->payload);

        if (! $delivery instanceof Delivery) {
            return [
                'message' => 'No outbound email on record for this Message-Id, ignored',
                'message_id' => FileEmailDeliveryAction::normalizeMessageId($this->payload->messageId()),
            ];
        }

        if (! new RecordEmailEventOnDeliveryAction($delivery, $event, $this->payload)->execute()) {
            return ['message' => 'Mailgun event already recorded', 'delivery_id' => $delivery->getId()];
        }

        $this->applyDestinationRemediation($delivery, $event, $this->payload->recipient());

        return [
            'message' => 'Mailgun delivery event recorded',
            'status' => $delivery->status->value,
            'event' => $event->value,
            'delivery_id' => $delivery->getId(),
            'message_id' => $delivery->message_id,
        ];
    }

    /**
     * A permanent failure is about the address, so only that address is marked bounced. A spam
     * complaint or an unsubscribe is the person asking us to stop, and a stop request is person-wide
     * (see Guild/Customers/CLAUDE.md), so it goes through the same action a STOP text does.
     */
    private function applyDestinationRemediation(
        Delivery $delivery,
        DeliveryStatusEnum $event,
        string $recipient
    ): void {
        if ($event !== DeliveryStatusEnum::FAILED && ! $event->isStopRequest()) {
            return;
        }

        $recipient = $recipient !== '' ? $recipient : (string) $delivery->to_address;
        if ($recipient === '') {
            return;
        }

        $people = PeoplesRepository::getByAnyContact(
            app: $delivery->app,
            company: $delivery->company,
            emails: [$recipient],
        )->get();
        $lead = $this->leadOf($delivery);

        foreach ($people as $person) {
            /** @var People $person */
            if ($event === DeliveryStatusEnum::FAILED) {
                $person->markEmailBounced($recipient);

                continue;
            }

            new ApplyDoNotContactAction(
                people: $person,
                sourceChannel: LeadCommunicationChannelEnum::EMAIL->value,
                lead: $lead?->peoples_id === $person->getId() ? $lead : null,
                reason: $event === DeliveryStatusEnum::COMPLAINED
                    ? 'Marked our email as spam (Mailgun complaint)'
                    : 'Unsubscribed through Mailgun',
            )->execute();
        }
    }

    /**
     * A delivery is filed per person; the lead it was sent about hangs off its message.
     */
    private function leadOf(Delivery $delivery): ?Lead
    {
        $message = $delivery->message_id !== null ? Message::query()->find($delivery->message_id) : null;

        $lead = $message?->entity(Lead::class);

        return $lead instanceof Lead ? $lead : null;
    }
}

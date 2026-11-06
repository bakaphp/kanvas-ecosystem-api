<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Mailgun\Actions\FileEmailDeliveryAction;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Mailgun's delivery events for outbound email — the email side of what
 * ProcessTwilioMessageStatusWebhookJob does for SMS. Opens stay with EmailOpenedWebhookJob.
 *
 * The event is matched by its Message-Id to the communication delivery FileEmailDeliveryAction filed at
 * send time, and the tenant comes from that delivery, not the receiver: one sending domain can serve
 * several companies, and Mailgun calls a single webhook URL per domain. An event for mail we have no
 * delivery for (sent by another system, or before this existed) is ignored rather than guessed onto a
 * tenant.
 */
#[WorkflowAction(
    name: 'Mailgun Email Delivery Status',
    description: 'Receiver for Mailgun delivery events on outbound email (delivered, failed, '
        . 'complained, unsubscribed): records whether an email was delivered or failed on the message, '
        . 'marks a hard-bounced address as bounced, and treats a spam complaint or an unsubscribe as a '
        . 'stop request. Register it with kanvas:mailgun-setup-delivery-webhooks. Opens are a separate '
        . 'receiver.',
    integration: IntegrationsEnum::MAILGUN,
)]
class EmailDeliveryWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = new MailgunEngagementPayloadService($this->webhookRequest->payload);
        $event = DeliveryStatusEnum::fromEvent($payload->event(), $payload->severity());

        if ($event === null) {
            return ['message' => 'Event ' . ($payload->event() ?: 'without a name') . ' is not a delivery event, ignored'];
        }

        $messageId = FileEmailDeliveryAction::normalizeMessageId($payload->messageId());
        $delivery = $messageId === '' ? null : Delivery::query()
            ->fromApp($this->receiver->app)
            ->where('provider', IntegrationsEnum::MAILGUN->value)
            ->where('external_id', $messageId)
            ->first();

        if (! $delivery instanceof Delivery) {
            return ['message' => 'No outbound email on record for this Message-Id, ignored', 'message_id' => $messageId];
        }

        $statusBefore = $delivery->status;
        $errorCode = $event->isFailure() ? $payload->deliveryStatusCode() : null;

        $recorded = new RecordDeliveryEventAction(
            delivery: $delivery,
            status: $event->toDeliveryStatus(),
            providerStatus: $event->value,
            payload: $payload->eventData(),
            occurredAt: $payload->occurredAt(),
            errorCode: $errorCode !== null ? (string) $errorCode : null,
            error: $event->isFailure() ? $payload->deliveryStatusMessage() : null,
        )->execute();

        if (! $recorded) {
            return ['message' => 'Mailgun event already recorded', 'delivery_id' => $delivery->getId()];
        }

        if ($delivery->status !== $statusBefore) {
            new RecordDeliveryOnMessageAction($delivery)->execute();
        }

        $this->applyDestinationRemediation($delivery, $event, $payload->recipient());

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
    protected function applyDestinationRemediation(
        Delivery $delivery,
        DeliveryStatusEnum $event,
        string $recipient,
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

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return new VerifyMailgunWebhookSignatureAction(
            $receiver->app,
            $receiver->company,
            (string) $request->input('signature.token', ''),
            (string) $request->input('signature.timestamp', ''),
            (string) $request->input('signature.signature', ''),
        )->execute();
    }
}

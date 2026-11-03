<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Connectors\Mailgun\Actions\RecordEmailAttemptAction;
use Kanvas\Connectors\Mailgun\Actions\RecordEmailDeliveryEventAction;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\MessageAttempt;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Mailgun's delivery events for outbound email — the email side of what
 * ProcessTwilioMessageStatusWebhookJob does for SMS. Opens stay with EmailOpenedWebhookJob.
 *
 * The event is matched to the attempt RecordEmailAttemptAction filed at send time by its Message-Id,
 * and the tenant comes from that attempt, not the receiver: one sending domain can serve several
 * companies, and Mailgun calls a single webhook URL per domain. An event for mail we have no attempt
 * for (sent by another system, or before this existed) is ignored rather than guessed onto a tenant.
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
        $status = DeliveryStatusEnum::fromEvent($payload->event(), $payload->severity());

        if ($status === null) {
            return ['message' => 'Event ' . ($payload->event() ?: 'without a name') . ' is not a delivery event, ignored'];
        }

        $messageId = RecordEmailAttemptAction::normalizeMessageId($payload->messageId());
        $attempt = $messageId === '' ? null : MessageAttempt::query()
            ->fromApp($this->receiver->app)
            ->where('provider', $status->provider())
            ->where('provider_message_id', $messageId)
            ->first();

        if (! $attempt instanceof MessageAttempt) {
            return ['message' => 'No outbound email on record for this Message-Id, ignored', 'message_id' => $messageId];
        }

        $recorded = new RecordEmailDeliveryEventAction($attempt, $payload)->execute($status);

        if (! $recorded['created']) {
            return ['message' => 'Mailgun event already recorded', 'attempt_id' => $attempt->getId()];
        }

        $message = $attempt->message;
        if ($recorded['advanced'] && $message instanceof Message) {
            new RecordDeliveryOnMessageAction($message)->execute(
                $status,
                $attempt->provider_message_id,
                $attempt->last_error_code !== null ? (string) $attempt->last_error_code : null,
                $attempt->last_error_message,
            );
        }

        $this->applyDestinationRemediation($attempt, $status, $payload->recipient());

        return [
            'message' => 'Mailgun delivery event recorded',
            'status' => $status->value,
            'attempt_id' => $attempt->getId(),
            'event_id' => $recorded['event']->getId(),
            'message_id' => $message?->getId(),
        ];
    }

    /**
     * A permanent failure is about the address, so only that address is marked bounced. A spam
     * complaint or an unsubscribe is the person asking us to stop, and a stop request is person-wide
     * (see Guild/Customers/CLAUDE.md), so it goes through the same action a STOP text does.
     */
    protected function applyDestinationRemediation(
        MessageAttempt $attempt,
        DeliveryStatusEnum $status,
        string $recipient,
    ): void {
        if ($status->remediationAction() === null) {
            return;
        }

        $recipient = $recipient !== '' ? $recipient : strtolower(trim((string) $attempt->to_address));
        if ($recipient === '') {
            return;
        }

        $people = PeoplesRepository::getByAnyContact(
            app: $attempt->app,
            company: $attempt->company,
            emails: [$recipient],
        )->get();
        $lead = $attempt->lead_id === null ? null : Lead::query()
            ->fromApp($attempt->app)
            ->fromCompany($attempt->company)
            ->whereKey($attempt->lead_id)
            ->first();

        foreach ($people as $person) {
            /** @var People $person */
            if ($status === DeliveryStatusEnum::FAILED) {
                $person->markEmailBounced($recipient);

                continue;
            }

            new ApplyDoNotContactAction(
                people: $person,
                sourceChannel: LeadCommunicationChannelEnum::EMAIL->value,
                lead: $lead?->peoples_id === $person->getId() ? $lead : null,
                reason: $status === DeliveryStatusEnum::COMPLAINED
                    ? 'Marked our email as spam (Mailgun complaint)'
                    : 'Unsubscribed through Mailgun',
            )->execute();
        }
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

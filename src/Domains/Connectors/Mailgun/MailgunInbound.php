<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun;

use Illuminate\Http\Request;
use Kanvas\Communication\Contracts\Concerns\HasInboundDefaults;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundSenderPolicy;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Connectors\Mailgun\Actions\RecordMailgunDeliveryEventAction;
use Kanvas\Connectors\Mailgun\Actions\RecordMailgunOpenAction;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Connectors\Mailgun\Services\MailgunPayloadService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Services\ConsentKeywordService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Mailgun's side of the communication webhook. Two payload shapes reach one URL: a route forward
 * (form fields, `sender`/`recipient`, the parsed mail) and a domain event (JSON, `event-data`), each
 * signed with the same key in its own place.
 */
class MailgunInbound implements InboundSource
{
    use HasInboundDefaults;

    /**
     * The kind of attachment the shared pipeline hands back: the file was already stored by the
     * receiver (`uploaded_files`), so the "url" is its Filesystem id.
     */
    public const string STORED_FILE = 'kanvas/filesystem';

    #[Override]
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        return VerifyMailgunWebhookSignatureAction::forRequest($request, $receiver)->execute();
    }

    #[Override]
    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        if (! $this->isRouteForward($payload)) {
            return [];
        }

        $mail = new MailgunPayloadService($payload);

        // Vacation responders, bounce notices and list mail: an agent that answers these ends up in
        // a loop with another robot.
        if ($mail->sender() === '' || $mail->isAutoReply()) {
            return [];
        }

        $ourAddress = strtolower(trim((string) $payload['recipient']));
        $agent = new AgentMailboxService()->personalOwnerAt($receiver->app, $receiver->company, $ourAddress);

        // The agent's own reply comes back through the route when it is cc'd or a list echoes it,
        // and a mail with no readable body gives it nothing to answer — unless its subject is the
        // whole message, which is how an unsubscribe usually arrives.
        $saysNothing = $mail->text() === '' && ConsentKeywordService::detect($mail->subject()) === null;

        if ($agent !== null && ($mail->sender() === $ourAddress || $saysNothing)) {
            return [];
        }

        return [$this->parse($payload, $mail, $agent)];
    }

    #[Override]
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        if (! is_array($payload['event-data'] ?? null)) {
            return null;
        }

        $event = new MailgunEngagementPayloadService($payload);

        return $event->event() === 'opened'
            ? new RecordMailgunOpenAction($receiver->app, $receiver->company, $event)->execute()
            : new RecordMailgunDeliveryEventAction($receiver->app, $event)->execute();
    }

    #[Override]
    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        /** @var Filesystem $file */
        $file = Filesystem::getById((int) $attachment->url, $message->app);

        return $file;
    }

    private function isRouteForward(array $payload): bool
    {
        return isset($payload['sender'], $payload['recipient']) && ! isset($payload['event-data']);
    }

    /**
     * @param Agent|null $agent the agent whose own mailbox the mail was sent to
     */
    private function parse(array $payload, MailgunPayloadService $mail, ?Agent $agent): InboundMessage
    {
        $from = $mail->sender();
        $ourAddress = strtolower(trim((string) $payload['recipient']));
        $messageId = $mail->messageId();

        return new InboundMessage(
            provider: MailgunHandler::slug(),
            channel: ChannelTypeEnum::EMAIL,
            inboundIdentity: $ourAddress,
            from: $from,
            providerMessageId: $messageId !== '' ? $messageId : null,
            conversationSlug: EmailTransport::channelSlug($from, $agent),
            messageVerb: EmailTransport::MESSAGE_VERB,
            messageSlug: $messageId !== '' ? 'mailgun-' . md5($messageId) : null,
            text: $mail->text() !== '' ? $mail->text() : null,
            fromName: $mail->senderName(),
            // A one-word "Unsubscribe" lands in the subject at least as often as in the body,
            // usually with nothing under it.
            consentSignal: ConsentKeywordService::detect($mail->text()) ?? ConsentKeywordService::detect($mail->subject()),
            // Both ends and the thread, so a reply leaves from the address the mail reached and
            // threads under it.
            raw: [
                'sender' => $from,
                'recipient' => $ourAddress,
                'from' => (string) ($payload['from'] ?? ''),
                'subject' => $mail->subject(),
                'message_id' => $messageId,
                'in_reply_to' => $mail->inReplyTo(),
                'references' => $mail->references(),
            ],
            attachments: $this->attachments($payload, $mail),
            personTags: ['email', 'mailgun'],
            senderPolicy: $agent !== null
                ? InboundSenderPolicy::assistant(new AgentMailboxService()->accessFor($agent)->allowsUnknownSenders())
                : null,
        );
    }

    /**
     * The receiver already stored the attachments (`uploaded_files`); the ones embedded in the body
     * — signature logos — are not attachments anyone sent.
     *
     * @return list<InboundAttachment>
     */
    private function attachments(array $payload, MailgunPayloadService $mail): array
    {
        $inline = $mail->inlineAttachmentFields();
        $attachments = [];

        foreach ((array) ($payload['uploaded_files'] ?? []) as $file) {
            if (! is_array($file) || in_array($file['field'] ?? '', $inline, true)) {
                continue;
            }

            $filesystemId = (int) ($file['filesystem_id'] ?? 0);

            if ($filesystemId === 0) {
                continue;
            }

            $attachments[] = new InboundAttachment((string) $filesystemId, self::STORED_FILE);
        }

        return $attachments;
    }
}

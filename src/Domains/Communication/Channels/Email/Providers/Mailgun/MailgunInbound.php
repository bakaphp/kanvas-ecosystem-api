<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun;

use Illuminate\Http\Request;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\RecordMailgunDeliveryEventAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\RecordMailgunOpenAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunPayloadService;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\Actions\FlushInboundBurstAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
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

        return [$this->parse($payload, $mail)];
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

    /**
     * Email threads itself: each mail is announced as it arrives.
     */
    #[Override]
    public function burstPolicy(InboundMessage $inbound, Companies $company): ?BurstPolicy
    {
        return null;
    }

    #[Override]
    public function burstHandler(): string
    {
        return FlushInboundBurstAction::class;
    }

    #[Override]
    public function answersHelpItself(): bool
    {
        return false;
    }

    private function isRouteForward(array $payload): bool
    {
        return isset($payload['sender'], $payload['recipient']) && ! isset($payload['event-data']);
    }

    private function parse(array $payload, MailgunPayloadService $mail): InboundMessage
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
            conversationSlug: EmailTransport::channelSlug($from),
            messageVerb: EmailTransport::MESSAGE_VERB,
            messageSlug: $messageId !== '' ? 'mailgun-' . md5($messageId) : null,
            text: $mail->text() !== '' ? $mail->text() : null,
            // Both ends and the thread, so a reply leaves from the address the mail reached and
            // threads under it.
            raw: [
                'sender' => $from,
                'recipient' => $ourAddress,
                'from' => (string) ($payload['from'] ?? ''),
                'from_name' => $mail->senderName(),
                'subject' => $mail->subject(),
                'message_id' => $messageId,
                'in_reply_to' => $mail->inReplyTo(),
                'references' => $mail->references(),
            ],
            attachments: $this->attachments($payload, $mail),
            personTags: ['email', 'mailgun'],
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

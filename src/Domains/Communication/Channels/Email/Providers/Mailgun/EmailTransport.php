<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\FileEmailDeliveryAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * Email through Mailgun's send API, from the connection's address. A reply threads under the mail
 * it answers (`In-Reply-To` / `References`), which is what keeps it in the customer's conversation.
 */
class EmailTransport implements Transport
{
    public const string MESSAGE_VERB = 'mailgun-email';

    #[Override]
    public function send(Delivery $delivery, Content $content): TransportResult
    {
        $connection = $delivery->connection;
        $replied = $this->repliedMessage($delivery);

        try {
            $messageId = $this->client($delivery)->sendMessage(
                domain: (string) ($connection->config['domain'] ?? Str::after($connection->account_key, '@')),
                from: $delivery->company->name . ' <' . $connection->account_key . '>',
                to: (string) $delivery->to_address,
                subject: $this->subject($content, $replied, $delivery),
                text: $content->text,
                html: MarkdownEmailRenderer::toEmailHtml($content->text),
                headers: $this->threadingHeaders($replied),
                attachments: $this->fetchAttachments($content->attachments),
            );
        } catch (ValidationException $exception) {
            // Mailgun answered and refused (Client::assertOk). A connection error is not a verdict
            // on the email and is left to bubble, so the delivery is recorded as unknown.
            return TransportResult::failed('rejected_by_provider', $exception->getMessage());
        }

        return TransportResult::accepted(FileEmailDeliveryAction::normalizeMessageId($messageId));
    }

    /**
     * Mailgun's fate for a message only arrives as an event.
     */
    #[Override]
    public function lookup(Delivery $delivery): ?TransportResult
    {
        return null;
    }

    #[Override]
    public function conversation(Connection $connection, string $address): ConversationRef
    {
        return self::conversationBetween($connection->account_key, $address);
    }

    /**
     * An inbound mail filed by the communication webhook carries both ends: `recipient` is our
     * address, `sender` is theirs. A mail filed before the move stored only its text.
     */
    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        $raw = $message->message['raw_data'] ?? null;

        $isInboundMail = $message->messageType?->verb === self::MESSAGE_VERB
            && is_array($raw)
            && ! empty($raw['recipient'])
            && ! empty($raw['sender']);

        return $isInboundMail ? self::conversationBetween($raw['recipient'], $raw['sender']) : null;
    }

    /**
     * The slug every inbound email has been filed under, so a reply lands in the conversation the
     * customer's mail is already in.
     */
    public static function channelSlug(string $theirAddress): string
    {
        return SessionChannelService::createChannelSlug('email', strtolower($theirAddress));
    }

    protected function client(Delivery $delivery): Client
    {
        return new Client($delivery->app);
    }

    private function repliedMessage(Delivery $delivery): ?Message
    {
        $messageId = $delivery->request?->reply_to_message_id;

        return $messageId !== null ? Message::query()->find($messageId) : null;
    }

    /**
     * The caller's subject, else the thread the reply continues, else the company's name: a mail
     * without one is what spam filters are built for.
     */
    private function subject(Content $content, ?Message $replied, Delivery $delivery): string
    {
        if ($content->subject !== null && $content->subject !== '') {
            return $content->subject;
        }

        $thread = Str::trimToNull((string) ($replied?->message['subject'] ?? $replied?->message['raw_data']['subject'] ?? ''));

        if ($thread !== null) {
            return preg_match('/^\s*re:/i', $thread) === 1 ? $thread : 'Re: ' . $thread;
        }

        return (string) $delivery->company->name;
    }

    /**
     * @return array<string, string>
     */
    private function threadingHeaders(?Message $replied): array
    {
        $raw = is_array($replied?->message['raw_data'] ?? null) ? $replied->message['raw_data'] : [];
        $inReplyTo = Str::trimToNull((string) ($replied?->message['email_message_id'] ?? $raw['message_id'] ?? ''));

        if ($inReplyTo === null) {
            return [];
        }

        $references = trim(((string) ($replied->message['email_references'] ?? $raw['references'] ?? '')) . ' ' . $inReplyTo);

        return ['In-Reply-To' => $inReplyTo, 'References' => $references];
    }

    /**
     * @param list<Attachment> $attachments
     *
     * @return list<array{filename: string, contents: string}>
     */
    private function fetchAttachments(array $attachments): array
    {
        return array_map(fn (Attachment $attachment): array => [
            'filename' => $attachment->name ?? Str::fileNameFromUrl($attachment->url, 'attachment'),
            'contents' => SafeUrlFetcher::fetch($attachment->url),
        ], $attachments);
    }

    private static function conversationBetween(string $ourAddress, string $theirAddress): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::EMAIL,
            provider: MailgunHandler::slug(),
            accountKey: strtolower($ourAddress),
            thread: strtolower($theirAddress),
            channelSlug: self::channelSlug($theirAddress),
            messageVerb: self::MESSAGE_VERB
        );
    }
}

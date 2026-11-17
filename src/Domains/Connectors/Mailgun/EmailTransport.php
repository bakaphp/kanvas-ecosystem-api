<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Mailgun\Actions\FileEmailDeliveryAction;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Throwable;

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
        $mailboxOwner = $connection->personalOwner();
        $composer = new EmailComposer($delivery, $content);
        $people = $delivery->request?->people;

        try {
            $attachments = $this->fetchAttachments($content->attachments);
        } catch (Throwable $exception) {
            return TransportResult::failed('attachment_unavailable', $exception->getMessage());
        }

        try {
            $messageId = $this->client($delivery)->sendMessage(
                domain: MailgunHandler::domainOf($connection),
                from: ($mailboxOwner?->name ?? $delivery->company->name) . ' <' . $connection->account_key . '>',
                to: (string) $delivery->to_address,
                subject: $composer->subject(),
                text: $composer->plainText(),
                html: $composer->html(),
                headers: $this->headers($composer->repliedMessage(), $mailboxOwner !== null ? $connection->account_key : null),
                cc: $composer->options->cc,
                attachments: $attachments,
                trackOpens: $composer->options->trackOpens && $people !== null,
                variables: $this->trackingVariables($composer->options, $people),
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
        return self::conversationBetween($connection->account_key, $address, $connection->personalOwner());
    }

    /**
     * An inbound mail filed by the communication webhook carries both ends: `recipient` is our
     * address, `sender` is theirs. A mail filed by the legacy receivers stored only its text.
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
     * customer's mail is already in. An agent's own mailbox keeps a thread per agent and sender: two
     * agents writing to the same person must not land in one thread and read each other's history.
     */
    public static function channelSlug(string $theirAddress, ?Agent $mailboxOwner = null): string
    {
        $slug = SessionChannelService::createChannelSlug('email', strtolower($theirAddress));

        return $mailboxOwner !== null ? 'agent-' . (int) $mailboxOwner->getId() . '-' . $slug : $slug;
    }

    protected function client(Delivery $delivery): Client
    {
        return new Client($delivery->app);
    }

    /**
     * A reply threads under the mail it answers. Mail from an agent's own mailbox always names that
     * mailbox as Reply-To: an answer that lands anywhere else never reaches the agent.
     *
     * @return array<string, string>
     */
    private function headers(?Message $replied, ?string $mailbox): array
    {
        $headers = $mailbox !== null ? ['Reply-To' => $mailbox] : [];
        $raw = is_array($replied?->message['raw_data'] ?? null) ? $replied->message['raw_data'] : [];
        $inReplyTo = Str::trimToNull((string) ($replied?->message['email_message_id'] ?? $raw['message_id'] ?? ''));

        if ($inReplyTo === null) {
            return $headers;
        }

        $references = trim(((string) ($replied->message['email_references'] ?? $raw['references'] ?? '')) . ' ' . $inReplyTo);

        return $headers + ['In-Reply-To' => $inReplyTo, 'References' => $references];
    }

    /**
     * What an open event carries back: the person, the lead it was about, and the campaign slot —
     * the person alone is ambiguous once they sit in several campaigns.
     *
     * @return array<string, string>
     */
    private function trackingVariables(EmailOptions $options, ?People $people): array
    {
        if (! $options->trackOpens || $people === null) {
            return [];
        }

        return array_map(strval(...), array_filter([
            'people_id' => $people->getId(),
            'lead_id' => $options->leadId,
            'campaign_recipient_id' => $options->campaignRecipientId,
        ]));
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

    private static function conversationBetween(
        string $ourAddress,
        string $theirAddress,
        ?Agent $mailboxOwner = null
    ): ConversationRef {
        return new ConversationRef(
            channel: ChannelTypeEnum::EMAIL,
            provider: MailgunHandler::slug(),
            accountKey: strtolower($ourAddress),
            thread: strtolower($theirAddress),
            channelSlug: self::channelSlug($theirAddress, $mailboxOwner),
            messageVerb: self::MESSAGE_VERB
        );
    }
}

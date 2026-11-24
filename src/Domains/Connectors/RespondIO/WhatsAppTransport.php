<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Support\Str;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum;
use Kanvas\Connectors\RespondIO\Handlers\RespondIOHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Override;
use RuntimeException;

/**
 * A message to a Respond.io contact, addressed by phone. Respond.io takes one thing per call, so
 * the files go first, one message each, and the text closes: that is the message the delivery is
 * known by.
 */
class WhatsAppTransport implements Transport
{
    public const string MESSAGE_VERB = MessageTypeEnum::TEXT->value;

    private const string VERB_PREFIX = 'respondio-';

    #[Override]
    public function send(Delivery $delivery, Content $content): TransportResult
    {
        try {
            $client = $this->client($delivery);
        } catch (ValidationException $exception) {
            return TransportResult::failed('missing_credentials', $exception->getMessage());
        }

        if ($content->text === '' && $content->attachments === []) {
            return TransportResult::failed('empty_message', 'There is nothing to send.');
        }

        $to = Str::toE164((string) $delivery->to_address);
        $warnings = [];
        $response = null;

        try {
            foreach ($content->attachments as $attachment) {
                $type = $this->attachmentType($attachment);

                if ($type === null) {
                    if ($content->attachmentsRequired) {
                        return TransportResult::failed('attachment_unsupported', 'Respond.io cannot carry ' . $attachment->url);
                    }

                    $warnings[] = 'attachments_dropped';

                    continue;
                }

                $response = $client->sendAttachment($to, $type, $attachment->url);
            }

            if ($content->text !== '') {
                $response = $client->sendMessage($to, $content->text);
            }
        } catch (ValidationException $exception) {
            // Respond.io answered and refused (Client::handleResponse). A dropped connection is not
            // a verdict and is left to bubble, so the delivery is recorded as unknown.
            return TransportResult::failed('rejected_by_provider', $exception->getMessage());
        }

        // Every file was dropped and there was no text to carry on with: nothing left.
        if ($response === null) {
            return TransportResult::failed(
                'attachment_unsupported',
                'Respond.io cannot carry any of the attachments, and there is no text to send.'
            );
        }

        $messageId = $response['messageId'] ?? null;

        if ($messageId === null) {
            throw new RuntimeException('Respond.io accepted the message without returning its id.');
        }

        return TransportResult::accepted((string) $messageId, array_values(array_unique($warnings)));
    }

    /**
     * What became of a message arrives as a webhook; there is nothing to ask.
     */
    #[Override]
    public function lookup(Delivery $delivery): ?TransportResult
    {
        return null;
    }

    #[Override]
    public function conversation(Connection $connection, string $address): ConversationRef
    {
        return self::conversationWith($connection->account_key, $address);
    }

    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        $contact = (string) ($message->message['chat_jid'] ?? '');

        if ($contact === '' || ! str_starts_with((string) $message->messageType?->verb, self::VERB_PREFIX)) {
            return null;
        }

        return self::conversationWith(RespondIOHandler::accountKey($message->app, $message->company), $contact);
    }

    /**
     * The slug every Respond.io conversation has been filed under since before this was a provider.
     */
    public static function channelSlug(string $contactPhone): string
    {
        return SessionChannelService::createChannelSlug('respondio', $contactPhone);
    }

    protected function client(Delivery $delivery): Client
    {
        return new Client($delivery->app, $delivery->company);
    }

    private static function conversationWith(string $accountKey, string $contactPhone): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::WHATSAPP,
            provider: RespondIOHandler::slug(),
            accountKey: $accountKey,
            thread: Str::toE164($contactPhone),
            channelSlug: self::channelSlug($contactPhone),
            messageVerb: self::MESSAGE_VERB
        );
    }

    private function attachmentType(Attachment $attachment): ?string
    {
        return match ($attachment->mediaType()) {
            MediaTypeEnum::IMAGE => 'image',
            MediaTypeEnum::VIDEO => 'video',
            MediaTypeEnum::AUDIO => 'audio',
            MediaTypeEnum::DOCUMENT => 'file',
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender;

use Baka\Support\Str;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * WhatsApp through a WaSender session, one recipient at a time. Each attachment is its own
 * WhatsApp message, sent before the text and spaced apart: this is an unofficial client, and a
 * number that fires several messages at once is the kind WhatsApp restricts.
 */
class WhatsAppTransport implements Transport
{
    /**
     * What our own replies are filed as. Inbound keeps the `whatsapp-*` type of its content.
     */
    public const string MESSAGE_VERB = 'whatsapp';

    private const string DIRECT_SUFFIX = '@s.whatsapp.net';

    private const int SECONDS_BETWEEN_PARTS = 30;

    #[Override]
    public function send(Delivery $delivery, Content $content): TransportResult
    {
        if ($content->text === '' && $content->attachments === []) {
            return TransportResult::failed('empty_message', 'There is nothing to send.');
        }

        $to = self::recipient((string) $delivery->to_address);

        try {
            $service = $this->messageService($delivery);
        } catch (ValidationException $exception) {
            return TransportResult::failed('missing_credentials', $exception->getMessage());
        }

        try {
            $response = [];

            foreach ($content->attachments as $index => $attachment) {
                if ($index > 0) {
                    $this->pause();
                }

                $response = $this->sendAttachment($service, $to, $attachment);
            }

            if ($content->text !== '') {
                if ($content->attachments !== []) {
                    $this->pause();
                }

                $response = $service->sendTextMessage($to, $content->text);
            }
        } catch (WaSenderRefusedException $exception) {
            // WaSender answered, so the outcome is known: it refused. A timeout or a dropped
            // connection is left to bubble and is recorded as unknown.
            return TransportResult::failed('rejected_by_provider', $exception->getMessage());
        }

        // The id WaSender reports the message under later; a response without one still left.
        return TransportResult::accepted(
            (string) ($response['data']['msgId'] ?? $response['data']['key']['id'] ?? $delivery->uuid)
        );
    }

    /**
     * WaSender has no endpoint to look a sent message up; its fate only arrives as an event.
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
     * A message filed before connections existed carries only the counterpart; our side is the one
     * WaSender number its company has. Groups and newsletters are not conversations with a person.
     */
    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        $jid = (string) ($message->message['chat_jid'] ?? '');
        $verb = (string) $message->messageType?->verb;

        if (! str_starts_with($verb, self::MESSAGE_VERB)
            || $jid === ''
            || ConversationTypeEnum::fromJid($jid) !== ConversationTypeEnum::DIRECT
        ) {
            return null;
        }

        $connection = WaSenderHandler::connectionFor($message->app, $message->company);

        return $connection !== null ? self::conversationBetween($connection->account_key, $jid) : null;
    }

    protected function messageService(Delivery $delivery): MessageService
    {
        return new MessageService(
            $delivery->app,
            $delivery->company,
            outbound: $this->startsTheConversation($delivery)
        );
    }

    /**
     * A company can keep a second session for messages it initiates, so the number that answers
     * customers is never the one that writes first. Someone who already wrote in keeps hearing
     * from the number they wrote to.
     */
    private function startsTheConversation(Delivery $delivery): bool
    {
        if (empty($delivery->app->get(ConfigurationEnum::BASE_URL_OUTBOUND->value))
            || $delivery->request?->reply_to_message_id !== null
        ) {
            return false;
        }

        return ! Channel::query()
            ->fromApp((int) $delivery->apps_id)
            ->fromCompany((int) $delivery->companies_id)
            ->where('slug', ConversationChannelService::channelSlug(self::jid((string) $delivery->to_address)))
            ->whereNotNull('last_message_id')
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function sendAttachment(MessageService $service, string $to, Attachment $attachment): array
    {
        return match ($attachment->mediaType()) {
            MediaTypeEnum::IMAGE => $service->sendImageMessage($to, $attachment->url),
            MediaTypeEnum::VIDEO => $service->sendVideoMessage($to, $attachment->url),
            MediaTypeEnum::AUDIO => $service->sendAudioMessage($to, $attachment->url),
            default => $service->sendDocumentMessage($to, $attachment->url, $attachment->name),
        };
    }

    protected function pause(): void
    {
        sleep(self::SECONDS_BETWEEN_PARTS);
    }

    /**
     * A counterpart WhatsApp only discloses a lid for is written to on that lid, suffix included;
     * everyone else on the number alone.
     */
    private static function recipient(string $address): string
    {
        return str_contains($address, '@') && ! str_ends_with($address, self::DIRECT_SUFFIX)
            ? $address
            : Str::digitsOnly(Str::before($address, '@'));
    }

    private static function jid(string $address): string
    {
        $recipient = self::recipient($address);

        return str_contains($recipient, '@') ? $recipient : $recipient . self::DIRECT_SUFFIX;
    }

    /**
     * The slug is the one the inbound webhook has always filed the chat under, so a message we
     * start lands in the channel the customer's answers arrive in.
     */
    private static function conversationBetween(string $ourNumber, string $theirAddress): ConversationRef
    {
        $jid = self::jid($theirAddress);

        return new ConversationRef(
            channel: ChannelTypeEnum::WHATSAPP,
            provider: WaSenderHandler::slug(),
            accountKey: $ourNumber,
            thread: self::recipient($jid),
            channelSlug: ConversationChannelService::channelSlug($jid),
            messageVerb: self::MESSAGE_VERB
        );
    }
}

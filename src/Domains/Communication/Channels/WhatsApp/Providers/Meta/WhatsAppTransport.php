<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta;

use Baka\Support\Str;
use Illuminate\Http\Client\RequestException;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Override;
use RuntimeException;

/**
 * WhatsApp over the Cloud API. Text only for now: attachments are dropped with a warning, or fail
 * the delivery when the caller required them.
 */
class WhatsAppTransport implements Transport
{
    public const string MESSAGE_VERB = 'meta-whatsapp';

    /**
     * The customer has not written in the last 24 hours, so Meta only takes an approved template.
     */
    private const string OUTSIDE_WINDOW_CODE = '131047';

    /**
     * Meta's own id for someone whose number it keeps to itself: a country code, a period and the
     * id, with `ENT.` in between for a parent id.
     */
    private const string USER_ID_PATTERN = '/^[A-Z]{2}\.[A-Za-z0-9.]+$/';

    #[Override]
    public function send(Delivery $delivery, Content $content): TransportResult
    {
        $warnings = [];

        if ($content->attachments !== []) {
            if ($content->attachmentsRequired) {
                return TransportResult::failed('attachments_unsupported', 'WhatsApp attachments are not sent yet.');
            }

            $warnings[] = 'attachments_dropped';
        }

        $recipient = self::recipient((string) $delivery->to_address);

        if ($recipient === null) {
            return TransportResult::failed('invalid_address', 'Not a WhatsApp number or a Meta user id: ' . $delivery->to_address);
        }

        $connection = $delivery->connection;
        $phoneNumberId = (string) ($connection->config['phone_number_id'] ?? '');

        try {
            $client = Client::forCompany($delivery->app, $delivery->company);
        } catch (RuntimeException $exception) {
            return TransportResult::failed('missing_credentials', $exception->getMessage());
        }

        try {
            $response = $client->sendMessage($phoneNumberId, [
                'recipient_type' => 'individual',
                ...$recipient,
                'type' => 'text',
                'text' => ['body' => $content->text, 'preview_url' => false],
            ]);
        } catch (RequestException $exception) {
            // Meta answered, so the outcome is known: it refused. A timeout or a dropped connection
            // is left to bubble and is recorded as unknown.
            $code = Client::errorCode($exception);

            return TransportResult::failed(
                $code === self::OUTSIDE_WINDOW_CODE ? 'outside_service_window' : ($code ?? (string) $exception->response->status()),
                Client::errorMessage($exception)
            );
        }

        $messageId = $response['messages'][0]['id'] ?? null;

        if ($messageId === null) {
            throw new RuntimeException('Meta accepted the message without returning its id.');
        }

        return TransportResult::accepted($messageId, $warnings);
    }

    /**
     * The Cloud API has no endpoint to look a sent message up; its fate only arrives as a status.
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
     * An inbound message carries both ends: `metadata.display_phone_number` is our number, `from`
     * is theirs — or `from_user_id`, when Meta keeps their number to itself.
     */
    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        $raw = $message->message['raw_data'] ?? null;

        if ($message->messageType?->verb !== self::MESSAGE_VERB
            || ! is_array($raw)
            || empty($raw['metadata']['display_phone_number'])
            || self::senderOf($raw) === null
        ) {
            return null;
        }

        return self::conversationBetween((string) $raw['metadata']['display_phone_number'], self::senderOf($raw));
    }

    /**
     * Who wrote a message Meta delivered: their number in E.164, or Meta's id for them when it
     * sends no number. The number wins when both are there, so a contact already on file keeps
     * their conversation. Which one it is comes from the field Meta put it in: an id that does not
     * read as one is nobody, never a number made of its digits.
     *
     * @param array<string, mixed> $message
     */
    public static function senderOf(array $message): ?string
    {
        $number = Str::trimToNull(Str::toE164((string) ($message['from'] ?? '')));

        if ($number !== null) {
            return $number;
        }

        $userId = (string) ($message['from_user_id'] ?? '');

        return self::isUserId($userId) ? $userId : null;
    }

    public static function isUserId(string $address): bool
    {
        return preg_match(self::USER_ID_PATTERN, $address) === 1;
    }

    /**
     * Meta's id is used whole and as sent; only a number is put in E.164.
     */
    public static function address(string $address): string
    {
        return self::isUserId($address) ? $address : Str::toE164($address);
    }

    /**
     * Meta takes its own id as `recipient`, and a number as `to` — which would win if both were
     * sent. Anything with a letter in it that is not Meta's id is neither: its digits are someone
     * else's number.
     *
     * @return array{recipient: string}|array{to: string}|null
     */
    private static function recipient(string $address): ?array
    {
        if (self::isUserId($address)) {
            return ['recipient' => $address];
        }

        // The full international number, digits only; normalizePhoneNumber() would drop a +1.
        $number = ltrim(Str::toE164($address), '+');

        return $number !== '' && preg_match('/\p{L}/u', $address) !== 1 ? ['to' => $number] : null;
    }

    /**
     * The slug is the one a contact's WhatsApp channel is opened with, so a conversation lands in
     * the channel the lead already has.
     */
    public static function channelSlug(string $theirAddress): string
    {
        return SessionChannelService::createChannelSlug('whatsapp', $theirAddress);
    }

    private static function conversationBetween(string $ourNumber, string $theirAddress): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::WHATSAPP,
            provider: MetaWhatsAppHandler::slug(),
            accountKey: Str::toE164($ourNumber),
            thread: self::address($theirAddress),
            channelSlug: self::channelSlug($theirAddress),
            messageVerb: self::MESSAGE_VERB
        );
    }
}

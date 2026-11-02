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
                // The full international number, digits only; normalizePhoneNumber() would drop a +1.
                'to' => ltrim(Str::toE164((string) $delivery->to_address), '+'),
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
     * is theirs.
     */
    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        $raw = $message->message['raw_data'] ?? null;

        if ($message->messageType?->verb !== self::MESSAGE_VERB
            || ! is_array($raw)
            || empty($raw['metadata']['display_phone_number'])
            || empty($raw['from'])
        ) {
            return null;
        }

        return self::conversationBetween((string) $raw['metadata']['display_phone_number'], (string) $raw['from']);
    }

    /**
     * The slug is the one a contact's WhatsApp channel is opened with, so a conversation lands in
     * the channel the lead already has.
     */
    public static function channelSlug(string $theirNumber): string
    {
        return SessionChannelService::createChannelSlug('whatsapp', $theirNumber);
    }

    private static function conversationBetween(string $ourNumber, string $theirNumber): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::WHATSAPP,
            provider: MetaWhatsAppHandler::slug(),
            accountKey: Str::toE164($ourNumber),
            thread: Str::toE164($theirNumber),
            channelSlug: self::channelSlug($theirNumber),
            messageVerb: self::MESSAGE_VERB
        );
    }
}

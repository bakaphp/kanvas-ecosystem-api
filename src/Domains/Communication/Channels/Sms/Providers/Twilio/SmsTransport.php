<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Services\MessageErrorClassifier;
use Kanvas\Communication\Channels\Sms\Rules\BodyLength;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client as TwilioClient;

/**
 * SMS and MMS over Twilio: the same Messages API call, with or without media.
 */
class SmsTransport implements Transport
{
    /**
     * Twilio's documented cap on media per message.
     */
    public const int MAX_MEDIA = 10;

    public const string MESSAGE_VERB = 'twilio-sms';

    /**
     * The recipient texted STOP to Twilio, which unsubscribed them itself and refuses the send.
     */
    private const int UNSUBSCRIBED_RECIPIENT_CODE = 21610;

    #[Override]
    public function send(Delivery $delivery, Content $content): TransportResult
    {
        $company = $delivery->company;
        $configuredLimit = (int) $company->get(ConfigurationEnum::TWILIO_MAX_MESSAGE_BODY_LENGTH->value);

        if (BodyLength::exceeds($content->text, $configuredLimit)) {
            return TransportResult::failed(
                'content_too_long',
                'The text exceeds ' . BodyLength::limit($configuredLimit) . ' characters.'
            );
        }

        $media = array_map(fn (Attachment $attachment): string => $attachment->url, $content->attachments);
        $warnings = [];

        if (count($media) > self::MAX_MEDIA) {
            if ($content->attachmentsRequired) {
                return TransportResult::failed(
                    'too_many_attachments',
                    'A text carries at most ' . self::MAX_MEDIA . ' attachments.'
                );
            }

            $warnings[] = 'attachments_truncated';
            $media = array_slice($media, 0, self::MAX_MEDIA);
        }

        $payload = $this->senderRoute($delivery->connection) + array_filter([
            'body' => $content->text,
            'mediaUrl' => $media,
            'statusCallback' => TwilioInbound::statusCallbackUrl($delivery->app, $company),
        ]);

        try {
            $message = $this->client($delivery)->messages->create((string) $delivery->to_address, $payload);
        } catch (RestException $exception) {
            // Twilio answered, so the outcome is known: it refused. Anything else — a timeout, a
            // dropped connection — is left to bubble, and is recorded as unknown.
            return TransportResult::failed(
                (string) $exception->getCode(),
                MessageErrorClassifier::classify($exception)['classification'] . ': ' . $exception->getMessage(),
                $exception->getCode() === self::UNSUBSCRIBED_RECIPIENT_CODE
            );
        }

        return TransportResult::accepted((string) $message->sid, $warnings);
    }

    #[Override]
    public function lookup(Delivery $delivery): ?TransportResult
    {
        $message = $this->client($delivery)->messages((string) $delivery->external_id)->fetch();
        $status = StatusMap::toDeliveryStatus((string) $message->status);

        if ($status === null) {
            return null;
        }

        return TransportResult::observed(
            $status,
            strtolower((string) $message->status),
            (string) $message->sid,
            $message->errorCode !== null ? (string) $message->errorCode : null,
            $message->errorMessage
        );
    }

    #[Override]
    public function conversation(Connection $connection, string $address): ConversationRef
    {
        return self::conversationBetween($connection->account_key, $address);
    }

    /**
     * An inbound text carries both ends: `To` is our number, `From` is theirs.
     */
    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        $raw = $message->message['raw_data'] ?? null;

        $isInboundText = $message->messageType?->verb === self::MESSAGE_VERB
            && is_array($raw)
            && ! empty($raw['To'])
            && ! empty($raw['From'])
            && $raw['To'] !== $raw['From'];

        return $isInboundText ? self::conversationBetween($raw['To'], $raw['From']) : null;
    }

    /**
     * The slug the inbound webhook has always filed texts under, so what a company sends lands in
     * the conversation its customer's texts are already in.
     */
    public static function channelSlug(string $theirNumber): string
    {
        return SessionChannelService::createChannelSlug('sms', $theirNumber);
    }

    protected function client(Delivery $delivery): TwilioClient
    {
        return Client::getInstanceByCompanyOrApp($delivery->company, $delivery->app);
    }

    /**
     * @return array{messagingServiceSid: string}|array{from: string}
     */
    private function senderRoute(Connection $connection): array
    {
        $messagingServiceSid = Str::trimToNull($connection->config['messaging_service_sid'] ?? null);

        return $messagingServiceSid !== null
            ? ['messagingServiceSid' => $messagingServiceSid]
            : ['from' => $connection->account_key];
    }

    private static function conversationBetween(string $ourNumber, string $theirNumber): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::SMS,
            provider: TwilioHandlers::slug(),
            accountKey: Str::toE164($ourNumber),
            thread: Str::toE164($theirNumber),
            channelSlug: self::channelSlug($theirNumber),
            messageVerb: self::MESSAGE_VERB
        );
    }
}

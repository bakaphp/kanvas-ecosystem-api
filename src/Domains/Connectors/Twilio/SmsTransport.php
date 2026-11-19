<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Sms\Rules\BodyLength;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Enums\MessageTypeEnum;
use Kanvas\Connectors\Twilio\Handlers\TwilioHandlers;
use Kanvas\Connectors\Twilio\Services\MessageErrorClassifier;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
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

    public const string MESSAGE_VERB = MessageTypeEnum::SMS->value;

    private const string VOIP_WITHOUT_SMS = 'VOIP without SMS';

    private const string MOBILE_LINE_TYPE = 'mobile';

    private const array VOIP_LINE_TYPES = ['fixedvoip', 'nonfixedvoip'];

    /**
     * Lookup classifies a VoIP line but cannot say whether texting is enabled on it; the carrier
     * behind the number is the only hint it gives. These are the wholesale networks behind the VoIP
     * numbers people really text from (Google Voice, TextNow...), plus the "SVR" marker of a
     * messaging-enabled line.
     */
    private const array VOIP_CARRIER_KEYWORDS = [
        'svr',
        'bandwidth',
        'onvoy',
        'sinch',
        'twilio',
        'telnyx',
        'peerless',
        'level 3',
        'enflick',
    ];

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

        $to = (string) $delivery->to_address;

        if (Str::toE164((string) $delivery->connection->account_key) === Str::toE164($to)) {
            return TransportResult::failed('same_number', 'A text cannot be sent to the number it leaves from.');
        }

        // Someone who texted us is reachable on that number by definition; a number we are about to
        // text unprompted is checked first.
        // Nothing has left yet, so a failure here is one that can be sent again, never an outcome
        // that was lost.
        try {
            $refusal = $delivery->request?->kind !== OperationKindEnum::REPLY
                ? $this->refusalForLine($delivery, $to)
                : null;
            $client = $this->client($delivery);
        } catch (ValidationException $exception) {
            return TransportResult::failed('missing_credentials', $exception->getMessage());
        } catch (TwilioException $exception) {
            return TransportResult::failed('lookup_failed', $exception->getMessage());
        }

        if ($refusal !== null) {
            return $refusal;
        }

        $payload = $this->senderRoute($delivery->connection) + array_filter([
            'body' => $content->text,
            'mediaUrl' => $media,
            'statusCallback' => TwilioInbound::statusCallbackUrl($delivery->app, $company),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        try {
            $message = $client->messages->create($to, $payload);
        } catch (RestException $exception) {
            // Twilio answered, so the outcome is known: it refused. Anything else — a timeout, a
            // dropped connection — is left to bubble, and is recorded as unknown.
            $classification = MessageErrorClassifier::classify($exception);

            // `opted_out` is Twilio's 21610: the recipient texted STOP to Twilio, which unsubscribed
            // them itself and refuses the send.
            return TransportResult::failed(
                (string) $exception->getCode(),
                $classification . ': ' . $exception->getMessage(),
                $classification === 'opted_out'
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

    /**
     * Texts only go to mobile numbers and to VoIP numbers on a carrier known to carry texts. An
     * allow-list on purpose: a line type Twilio adds later, or a number it could not classify, must
     * not slip through. What is already known about the number is asked first — a contact marked
     * invalid, a line an earlier lookup called a landline (kept on the person) — so neither pays for
     * another lookup.
     */
    private function refusalForLine(Delivery $delivery, string $to): ?TransportResult
    {
        $people = $delivery->request?->people;

        if ($people?->isPhoneUndeliverable($to) === true) {
            return TransportResult::failed(
                'undeliverable_number',
                $to . ' is marked invalid; no further texts are sent to it.'
            );
        }

        if ($people?->isPhoneLandline($to) === true) {
            return TransportResult::failed(
                'not_mobile',
                $to . ' is a landline; texts only go to mobile and VoIP numbers.'
            );
        }

        $line = $this->lineOf($delivery, $to);

        if (! $line->valid) {
            return TransportResult::failed('invalid_number', $to . ' is not a valid phone number.');
        }

        $received = trim((string) ($line->lineTypeIntelligence['type'] ?? ''));
        $lineType = strtolower($received);

        if ($lineType !== '') {
            $people?->setPhoneLandline($to, $lineType === 'landline');
        }

        $isVoip = in_array($lineType, self::VOIP_LINE_TYPES, true);

        if ($lineType !== self::MOBILE_LINE_TYPE && ! $isVoip) {
            $lookupError = $line->lineTypeIntelligence['error_code'] ?? null;

            return TransportResult::failed('not_mobile', sprintf(
                '%s has line type %s; texts only go to mobile and VoIP numbers.',
                $to,
                match (true) {
                    $lineType !== '' => $received,
                    $lookupError !== null => sprintf('unknown (Twilio lookup error %s)', $lookupError),
                    default => 'unknown (Twilio returned no line type)',
                }
            ));
        }

        $carrier = strtolower((string) ($line->lineTypeIntelligence['carrier_name'] ?? ''));

        // The person who wrote the text reads this reason as is, so it names no number or carrier.
        if ($isVoip && ! Str::contains($carrier, self::VOIP_CARRIER_KEYWORDS)) {
            return TransportResult::failed('voip_without_sms', self::VOIP_WITHOUT_SMS);
        }

        $lineStatus = strtolower((string) ($line->lineStatus['status'] ?? 'unknown'));

        return in_array($lineStatus, ['inactive', 'unreachable'], true)
            ? TransportResult::failed('line_' . $lineStatus, $to . ' is ' . $lineStatus . ' and cannot receive texts.')
            : null;
    }

    protected function lineOf(Delivery $delivery, string $number): object
    {
        return $this->client($delivery)
            ->lookups
            ->v2
            ->phoneNumbers($number)
            ->fetch(['fields' => 'line_type_intelligence,line_status']);
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

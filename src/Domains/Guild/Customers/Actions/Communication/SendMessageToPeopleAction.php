<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions\Communication;

use Baka\Support\Str;
use Exception;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\ConsentGuard;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * A message to a person with no lead behind them, on any channel: the one way in for a domain that
 * writes to a customer directly (a document link, a campaign). It leaves on the company's connection
 * for that channel, whatever vendor is behind it, so no caller names one.
 *
 * A send that is refused or skipped throws, which every caller treats as "not sent". The caller
 * files the message; what this returns names the delivery to link it to.
 */
final class SendMessageToPeopleAction
{
    /**
     * Files per text message. Twilio's documented cap is 10, but error 21623 fires on some account
     * and carrier combinations before that; 8 is the safe default measured in production. An app
     * overrides it with `twilio-mms-batch-size`.
     */
    private const int DEFAULT_MEDIA_BATCH_SIZE = 8;
    private const int MAX_MEDIA_BATCH_SIZE = 10;

    public function __construct(private readonly People $people)
    {
    }

    /**
     * @param string|null $to the address to reach them on; without one, addressFor() picks it
     * @param list<string> $attachmentUrls
     * @param string|null $emailTemplate only read for an email; see SendEmailToPeopleAction
     * @param string|null $fromAccount the company's number to text from, when it is not the default
     *
     * @return array<string, mixed>
     */
    public function execute(
        ChannelTypeEnum $channel,
        ?string $to,
        string $message,
        ?string $subject = null,
        array $attachmentUrls = [],
        ?Agent $fromAgent = null,
        ?string $emailTemplate = null,
        ?string $fromAccount = null
    ): array {
        if ($channel === ChannelTypeEnum::EMAIL) {
            return new SendEmailToPeopleAction($this->people)->execute(
                to: (string) $this->addressFor($channel, $to),
                message: $message,
                subject: $subject,
                attachmentUrls: $attachmentUrls,
                templateName: $emailTemplate,
                fromAgent: $fromAgent,
            );
        }

        $address = (string) $this->addressFor($channel, $to);

        if ($address !== '' && $this->hasOptedOut($address, $channel)) {
            throw new ValidationException('The message was not sent: the person opted out on ' . $address);
        }

        return $this->send(
            $channel,
            $address !== '' ? $this->phoneNumber($address, $channel) : '',
            Content::text($message)->withAttachments($attachmentUrls),
            Sender::speaking($fromAgent),
            $fromAccount
        );
    }

    /**
     * The send itself, for a caller that composed the content (an email with its template and
     * signature). Synchronous, so the outcome returned is the provider's answer.
     *
     * @return array{
     *     channel: string, to: string, from: string|null, people_id: int, provider: string,
     *     provider_message_id: string|null, operation_id: int, warnings: list<string>,
     *     channel_slug: string
     * }
     */
    public function send(
        ChannelTypeEnum $channel,
        string $to,
        Content $content,
        Sender $from,
        ?string $fromAccount = null
    ): array {
        if (trim($to) === '') {
            throw new ValidationException('People has no ' . $channel->value . ' address to send to');
        }

        $delivery = $this->deliver(
            $channel,
            $to,
            $content,
            $from,
            $fromAccount
        );

        if ($delivery?->wasAccepted() !== true) {
            throw new ValidationException(sprintf(
                'The %s was not sent: %s',
                $channel === ChannelTypeEnum::EMAIL ? 'email' : 'message',
                $delivery?->error
                    ?? $delivery?->error_code
                    ?? 'the company has no ' . $channel->value . ' connection to send from'
            ));
        }

        return [
            'channel' => $channel->value,
            'to' => $to,
            'from' => $delivery->connection?->account_key,
            'people_id' => $this->people->getId(),
            'provider' => $delivery->provider,
            'provider_message_id' => $delivery->external_id,
            'operation_id' => $delivery->request_id,
            // What the provider left out of a message it accepted (`attachments_dropped`): the
            // caller decides whether the message still did its job.
            'warnings' => $delivery->warnings ?? [],
            // Where a caller files the message so the customer's answer lands beside it: every
            // vendor names its conversations its own way.
            'channel_slug' => $delivery->conversation()->channelSlug,
        ];
    }

    /**
     * The address a message on the channel goes to. One the caller named is used as given.
     * Otherwise the person's contacts of that kind are walked by priority (`weight`, highest first)
     * and the first that can still be written to wins: not one they stopped, not one known to be
     * dead. When none can, the highest-priority one comes back anyway, so the caller's own checks
     * say why it was refused. Null when they have none at all.
     */
    public function addressFor(ChannelTypeEnum $channel, ?string $to = null): ?string
    {
        $named = Str::trimToNull($to);

        if ($named !== null) {
            return $named;
        }

        $kind = $channel === ChannelTypeEnum::EMAIL ? ContactTypeEnum::EMAIL : ContactTypeEnum::CELLPHONE;
        $contacts = $this->people->contacts()
            ->where('contacts_types_id', $kind->value)
            ->orderByDesc('weight')
            ->orderBy('id')
            ->get();
        $best = $contacts->first(fn (Contact $contact): bool => $contact->isDeliverable())
            ?? $contacts->first();

        return Str::trimToNull($best?->value);
    }

    /**
     * The person stopped this one address (a bounce, a flag pushed by a CRM). A stop request for the
     * whole person is the consent check every send goes through.
     */
    public function hasOptedOut(string $address, ChannelTypeEnum $channel): bool
    {
        return ConsentGuard::isDestinationOptedOut(
            $this->people,
            $address,
            $channel === ChannelTypeEnum::EMAIL
        );
    }

    /**
     * The number a text or WhatsApp really goes to, in E.164. A company testing with
     * `allow_session_hijack` sends every one of them to the first number in its
     * `overwrite_phone_number` setting instead of the customer; the setting's keys carry the
     * decoration of the channel they were captured on.
     */
    public function phoneNumber(string $cellphone, ChannelTypeEnum $channel): string
    {
        $company = $this->people->company;
        $testNumbers = $company->get('allow_session_hijack', false)
            ? $company->get('overwrite_phone_number')
            : null;

        if ($testNumbers === null) {
            return Str::toE164($cellphone);
        }

        $testNumber = array_key_first((array) $testNumbers);

        if ($testNumber === null) {
            throw new Exception('No hijack number found for this phone number');
        }

        $decoration = $channel === ChannelTypeEnum::WHATSAPP ? '@s.whatsapp.net' : 'twilio-';

        return Str::toE164(str_replace($decoration, '', (string) $testNumber));
    }

    /**
     * The files of a text, split into what one message may carry. One empty batch when there are
     * none, so the words still go out.
     *
     * @param list<string> $mediaUrls
     *
     * @return list<list<string>>
     */
    public function mediaBatches(array $mediaUrls): array
    {
        return $mediaUrls === [] ? [[]] : array_chunk($mediaUrls, $this->mediaBatchSize());
    }

    public function mediaBatchSize(): int
    {
        $configured = (int) $this->people->app->get(ConfigurationEnum::SMS_MEDIA_BATCH_SIZE->value);

        return max(1, min(self::MAX_MEDIA_BATCH_SIZE, $configured ?: self::DEFAULT_MEDIA_BATCH_SIZE));
    }

    /**
     * A text and its files, one message per batch with the words on the first. It stops at the
     * first message that did not leave, which is then the last delivery returned.
     *
     * @param list<list<string>> $batches from mediaBatches()
     *
     * @return list<Delivery|null>
     */
    public function deliverText(
        string $to,
        string $text,
        array $batches,
        ?Sender $from = null,
        ?string $fromAccount = null
    ): array {
        $deliveries = [];

        foreach ($batches as $index => $batch) {
            $deliveries[] = $delivery = $this->deliver(
                ChannelTypeEnum::SMS,
                $to,
                Content::text($index === 0 ? $text : '')->withAttachments($batch, required: true),
                $from,
                $fromAccount
            );

            if ($delivery?->wasAccepted() !== true) {
                break;
            }
        }

        return $deliveries;
    }

    /**
     * One message on one channel to this person, waited on: the single place a person is written to
     * through Communication, for the lead send as well. Nothing is thrown and nothing is filed. Null
     * when no connection could carry it; a delivery that was refused or skipped comes back as it
     * is, for a caller that reports why in its own words.
     *
     * @param string|null $fromAccount the company's number or mailbox to send from, when it is not
     *                                 the one the speaker owns or the channel's default
     */
    public function deliver(
        ChannelTypeEnum $channel,
        string $to,
        Content|string $content,
        ?Sender $from = null,
        ?string $fromAccount = null
    ): ?Delivery {
        return Communication::for($this->people->app, $this->people->company)
            ->send(
                $this->people,
                $content,
                channels: [$channel->value],
                from: $from,
                sync: true,
                toAddress: $to,
                fromAccount: $fromAccount,
                file: false
            )
            ->delivery($channel);
    }

    /**
     * The one acknowledgement a person who asked to stop may still receive, so it is the only send
     * that passes the consent check. See SendOptOutConfirmationAction; nothing else may call it.
     */
    public function deliverOptOutConfirmation(
        ChannelTypeEnum $channel,
        string $to,
        Content|string $content,
        ?string $fromAccount = null
    ): ?Delivery {
        return Communication::for($this->people->app, $this->people->company)
            ->confirmOptOut(
                $this->people,
                $content,
                $channel,
                $to,
                $fromAccount
            )
            ->delivery($channel);
    }
}

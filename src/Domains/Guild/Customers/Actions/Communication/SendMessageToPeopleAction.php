<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions\Communication;

use Baka\Support\Str;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
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
    public function __construct(
        private readonly People $people,
    ) {
    }

    /**
     * @param list<string> $attachmentUrls
     * @param string|null $emailTemplate only read for an email; see SendEmailToPeopleAction
     *
     * @return array<string, mixed>
     */
    public function execute(
        ChannelTypeEnum $channel,
        string $to,
        string $message,
        ?string $subject = null,
        array $attachmentUrls = [],
        ?Agent $fromAgent = null,
        ?string $emailTemplate = null
    ): array {
        if ($channel === ChannelTypeEnum::EMAIL) {
            return new SendEmailToPeopleAction($this->people)->execute(
                to: $to,
                message: $message,
                subject: $subject,
                attachmentUrls: $attachmentUrls,
                templateName: $emailTemplate,
                fromAgent: $fromAgent,
            );
        }

        return $this->send(
            $channel,
            Str::toE164($to),
            Content::text($message)->withAttachments($attachmentUrls),
            Sender::speaking($fromAgent)
        );
    }

    /**
     * The send itself, for a caller that composed the content (an email with its template and
     * signature). Synchronous, so the outcome returned is the provider's answer.
     *
     * @return array{channel: string, to: string, from: string|null, people_id: int, provider: string, provider_message_id: string|null, operation_id: int}
     */
    public function send(
        ChannelTypeEnum $channel,
        string $to,
        Content $content,
        Sender $from
    ): array {
        if (trim($to) === '') {
            throw new ValidationException('People has no ' . $channel->value . ' address to send to');
        }

        $delivery = $this->deliver(
            $channel,
            $to,
            $content,
            $from
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
        ];
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

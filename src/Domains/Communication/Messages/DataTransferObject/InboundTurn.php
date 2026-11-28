<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Exceptions\InboundNotAnsweredException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;

/**
 * An inbound message an agent may answer right now: the connection it arrived on is still on, and
 * the agent that owns it. Only `of()` makes one, at the moment of answering; it is never carried
 * across a queue, because the connection can be switched off or handed over while a turn waits.
 */
final readonly class InboundTurn
{
    private function __construct(
        public Connection $connection,
        public Agent $agent,
        public ChannelTypeEnum $medium,
        public string $recipient
    ) {
    }

    public static function subjectOf(Message $inbound): ?string
    {
        return Str::trimToNull((string) ($inbound->message['subject'] ?? $inbound->message['raw_data']['subject'] ?? ''));
    }

    /**
     * @throws InboundNotAnsweredException when nothing may answer the message
     */
    public static function of(Message $inbound): self
    {
        if ($inbound->message['from_me'] ?? false) {
            throw new InboundNotAnsweredException('from_us', 'Message is from our side of the conversation');
        }

        $communication = (array) ($inbound->message['communication'] ?? []);
        $connection = Connection::forFiledMessage($inbound);
        $medium = ChannelTypeEnum::tryFrom((string) ($communication['channel'] ?? ''));

        if ($connection === null || $medium === null) {
            throw new InboundNotAnsweredException('no_connection', 'The message did not arrive on a connection');
        }

        $queuedUnder = $communication['activation_version'] ?? null;
        $refusal = $connection->refusalAt($queuedUnder !== null ? (int) $queuedUnder : null);

        if ($refusal !== null) {
            throw new InboundNotAnsweredException(
                $refusal,
                'Automatic replies are off for this conversation: ' . $refusal
            );
        }

        return new self(
            $connection,
            $connection->agent()
                ?? throw new InboundNotAnsweredException('no_owner', 'No agent owns ' . $connection->account_key),
            $medium,
            Str::trimToNull((string) ($communication['thread'] ?? ''))
                ?? throw new InboundNotAnsweredException('no_thread', 'The conversation has no counterpart address')
        );
    }
}

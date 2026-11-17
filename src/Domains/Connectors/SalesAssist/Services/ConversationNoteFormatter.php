<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;

/**
 * How a conversation message reads as a note in a dealer CRM: "(Sms) Jane Doe: ..." for what we
 * sent, "Customer: ..." for what came in, "Agent Note: ..." for an internal note. One place so every
 * CRM connector files the same line.
 */
class ConversationNoteFormatter
{
    private const string DEFAULT_AGENT_NAME = 'Sally';

    public static function format(Message $message, string $note, bool $fromAgent): string
    {
        $verb = $message->messageType?->verb;
        $fromWho = match (true) {
            strtolower((string) $verb) === 'note' => 'Agent Note',
            $fromAgent => '(' . ucfirst(self::deliveryChannel($message, $verb)) . ') ' . self::agentName($message),
            default => 'Customer',
        };

        return $fromWho . ': ' . $note;
    }

    /**
     * The verb wins: a follow-up SMS can be filed under the person's email session channel,
     * and `channels->first()` is unordered (the lead conversation channel would read as "notes").
     */
    private static function deliveryChannel(Message $message, ?string $verb): string
    {
        $fromVerb = ChannelCategoryEnum::getLeadChannelNameFromVerb($verb);
        if ($fromVerb !== null) {
            return $fromVerb;
        }

        $channelSlug = $message->channels->first()?->slug;

        return $channelSlug ? ChannelCategoryEnum::getLeadChannelName($channelSlug) : ChannelCategoryEnum::SMS->value;
    }

    private static function agentName(Message $message): string
    {
        $name = trim(($message->user?->firstname ?? '') . ' ' . ($message->user?->lastname ?? ''));

        return $name !== '' ? $name : self::DEFAULT_AGENT_NAME;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Social\Enums;

use InvalidArgumentException;

/**
 * @todo move to agents?
 */
enum ChannelCategoryEnum: string
{
    case EMAIL = 'email';
    case MAILGUN = 'mailgun-email';
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';
    case RESPONDIO = 'respondio';
    case INTERNAL_NOTES = 'internal_notes';
    case SYSTEM_NOTES = 'system_notes';

    /**
     * Keywords found in a MessageType verb (e.g. 'twilio-sms', 'mailgun-email',
     * 'respondio-image') that mark it as a message actually delivered to a
     * customer over an external channel — as opposed to internal/system notes
     * or agent-internal turns. Substring matching (not exact value) so a single
     * 'respondio' entry covers every 'respondio-*' variant.
     */
    private const array COMMUNICATION_VERB_KEYWORDS = [
        'twilio',
        'sms',
        'mailgun',
        'email',
        'whatsapp',
        'voice',
        'respondio',
    ];

    public static function isCommunicationVerb(?string $verb): bool
    {
        if ($verb === null) {
            return false;
        }

        foreach (self::COMMUNICATION_VERB_KEYWORDS as $keyword) {
            if (str_contains($verb, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public static function validate(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException(
                "Invalid channel category '{$value}'. Valid options: " .
                implode(', ', array_column(self::cases(), 'value'))
            );
    }

    public static function getLeadChannelName(string $channelSlug): string
    {
        return match (true) {
            str_contains($channelSlug, 'wa-chat') => self::WHATSAPP->value,
            str_contains($channelSlug, 'twilio') => self::SMS->value,
            str_contains($channelSlug, 'email') => self::EMAIL->value,
            str_contains($channelSlug, 'mailgun') => self::MAILGUN->value,
            str_contains($channelSlug, 'note') => 'notes',
            default => 'notes',
        };
    }
}

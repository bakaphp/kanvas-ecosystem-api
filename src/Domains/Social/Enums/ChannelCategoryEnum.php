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
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';
    case INTERNAL_NOTES = 'internal_notes';
    case SYSTEM_NOTES = 'system_notes';

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
            str_contains($channelSlug, 'note') => 'notes',
            default => 'unknown',
        };
    }
}

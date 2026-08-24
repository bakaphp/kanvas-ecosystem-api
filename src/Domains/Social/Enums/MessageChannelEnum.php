<?php

declare(strict_types=1);

namespace Kanvas\Social\Enums;

/**
 * The customer-facing channel a report or query is narrowed to. Distinct from
 * ChannelCategoryEnum, which classifies a message type's verb — this is the coarse
 * SMS / email / everything selector the Engage reporting surfaces expose.
 */
enum MessageChannelEnum: string
{
    case SMS = 'sms';
    case EMAIL = 'email';
    case ALL = 'all';

    /**
     * Substrings that identify a message-type verb as belonging to this channel. Empty for ALL,
     * which matches every communication verb.
     *
     * @return array<int, string>
     */
    public function verbKeywords(): array
    {
        return match ($this) {
            self::SMS => ['sms', 'twilio'],
            self::EMAIL => ['email', 'mailgun'],
            self::ALL => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SMS => 'SMS only',
            self::EMAIL => 'Email only',
            self::ALL => 'SMS & email',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

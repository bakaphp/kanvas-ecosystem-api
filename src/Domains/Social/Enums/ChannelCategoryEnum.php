<?php

declare(strict_types=1);

namespace Kanvas\Social\Enums;

use InvalidArgumentException;

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
}

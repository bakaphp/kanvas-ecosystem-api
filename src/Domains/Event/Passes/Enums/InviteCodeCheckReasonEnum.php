<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Enums;

enum InviteCodeCheckReasonEnum: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case USED = 'used';
    case FULL = 'full';
    case USED_THIS_DAY = 'used_this_day';

    public function message(): string
    {
        return match ($this) {
            self::VALID => 'Access code is valid.',
            self::INVALID => 'Invalid access code.',
            self::USED => 'Access code already used.',
            self::FULL => 'No capacity left for this day.',
            self::USED_THIS_DAY => 'Access code already used for this day.',
        };
    }
}

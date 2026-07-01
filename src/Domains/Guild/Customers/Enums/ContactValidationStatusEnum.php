<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

enum ContactValidationStatusEnum: string
{
    case VALID = 'valid';
    case SOFT_BOUNCE = 'soft_bounce';
    case HARD_BOUNCE = 'hard_bounce';
    case INVALID = 'invalid';

    /** Dead address — must be replaced/excluded. Soft bounces are recoverable, so not here. */
    public function isPermanentFailure(): bool
    {
        return $this === self::HARD_BOUNCE || $this === self::INVALID;
    }
}

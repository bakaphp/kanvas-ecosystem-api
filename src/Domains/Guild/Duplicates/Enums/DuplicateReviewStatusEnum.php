<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Enums;

enum DuplicateReviewStatusEnum: string
{
    case PENDING = 'pending';
    case MERGED = 'merged';
    case DISMISSED = 'dismissed';
    case EXPIRED = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }
}

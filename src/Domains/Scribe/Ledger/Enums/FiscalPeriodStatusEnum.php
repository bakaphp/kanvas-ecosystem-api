<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Enums;

enum FiscalPeriodStatusEnum: string
{
    case OPEN = 'open';
    case SOFT_CLOSED = 'soft_closed';
    case HARD_CLOSED = 'hard_closed';

    public function acceptsPostings(): bool
    {
        return $this === self::OPEN;
    }

    public function acceptsPostingsWithOverride(): bool
    {
        return $this !== self::HARD_CLOSED;
    }
}

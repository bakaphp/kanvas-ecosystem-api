<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Enums;

enum ApprovalQueueStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }
}

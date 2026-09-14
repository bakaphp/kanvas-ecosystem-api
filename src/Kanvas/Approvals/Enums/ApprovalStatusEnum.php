<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

enum ApprovalStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::PENDING;
    }
}

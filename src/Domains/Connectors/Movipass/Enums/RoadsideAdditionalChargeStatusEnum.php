<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum RoadsideAdditionalChargeStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    /**
     * A rejected card is still money owed — the user can retry it — so it blocks closing the case
     * the same as pending.
     */
    public function isOutstanding(): bool
    {
        return $this === self::PENDING || $this === self::REJECTED;
    }
}

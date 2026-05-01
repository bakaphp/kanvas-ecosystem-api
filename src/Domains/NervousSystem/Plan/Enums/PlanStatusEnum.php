<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

enum PlanStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case AWAITING_APPROVAL = 'awaiting_approval';
    case DONE = 'done';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Statuses that count as "open" — agent is still pursuing the plan.
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::DRAFT, self::ACTIVE, self::BLOCKED, self::AWAITING_APPROVAL];
    }

    /**
     * Statuses that count as "terminal" — the plan won't change further.
     * @return array<int, self>
     */
    public static function terminalStatuses(): array
    {
        return [self::DONE, self::FAILED, self::CANCELLED];
    }
}

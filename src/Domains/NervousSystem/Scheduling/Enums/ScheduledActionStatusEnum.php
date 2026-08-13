<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Enums;

enum ScheduledActionStatusEnum: string
{
    case PENDING = 'pending';
    case EXECUTING = 'executing';
    case EXECUTED = 'executed';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Terminal states — the row will never fire again.
     *
     * @return array<int, self>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::EXECUTED,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}

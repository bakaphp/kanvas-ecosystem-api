<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Enums;

enum ScheduledActionStatusEnum: string
{
    case PENDING = 'pending';
    /**
     * Disabled from the UI. Not terminal — the row keeps its schedule and can be resumed. The sweep
     * only claims PENDING rows, so a paused row simply never becomes due.
     */
    case PAUSED = 'paused';
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

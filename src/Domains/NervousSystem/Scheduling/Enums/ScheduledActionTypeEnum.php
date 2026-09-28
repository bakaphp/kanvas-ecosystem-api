<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Enums;

enum ScheduledActionTypeEnum: string
{
    case REMINDER = 'reminder';
    case AGENT_TASK = 'agent_task';

    /**
     * Minimum seconds allowed between two occurrences of a recurring schedule of
     * this type. Reminders are nearly free (cron's native 1-min granularity);
     * agent tasks cost an LLM turn per fire, so they floor at 15 minutes.
     */
    public function minimumRecurrenceIntervalSeconds(): int
    {
        return match ($this) {
            self::REMINDER => 60,
            self::AGENT_TASK => 900,
        };
    }
}

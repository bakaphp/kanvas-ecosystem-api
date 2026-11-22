<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum WedgedWorkflowCauseEnum: string
{
    case ORPHANED_ACTIVITY_LOCK = 'orphaned-activity-lock';
    case WAITING_WITHOUT_ACTIVITY = 'waiting-without-activity';
    case UNKNOWN = 'unknown';

    public function repairDescription(): string
    {
        return match ($this) {
            self::ORPHANED_ACTIVITY_LOCK => 'clear the activity locks so the workflow resumes',
            self::WAITING_WITHOUT_ACTIVITY => 'mark the workflow failed',
            self::UNKNOWN => 'none, needs a look',
        };
    }
}

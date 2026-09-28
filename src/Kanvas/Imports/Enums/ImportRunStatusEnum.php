<?php

declare(strict_types=1);

namespace Kanvas\Imports\Enums;

enum ImportRunStatusEnum: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';
}

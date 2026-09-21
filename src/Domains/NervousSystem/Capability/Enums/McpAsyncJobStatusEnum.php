<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Enums;

enum McpAsyncJobStatusEnum: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';
}

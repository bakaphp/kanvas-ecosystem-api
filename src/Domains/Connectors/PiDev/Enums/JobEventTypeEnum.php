<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Enums;

/**
 * SSE event types emitted on GET /agents/work/:jobId/events. Consumed by the poller/stream
 * reader in PR2 to surface progress and capture the pull-request URL.
 */
enum JobEventTypeEnum: string
{
    case STATUS = 'status';
    case TEXT = 'text';
    case THINKING = 'thinking';
    case TOOL_START = 'tool_start';
    case TOOL_END = 'tool_end';
    case PULL_REQUEST = 'pull_request';
    case ERROR = 'error';
    case DONE = 'done';
}

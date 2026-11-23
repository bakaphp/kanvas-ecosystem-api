<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum StatusEnum: string
{
    case CONNECTED = 'connected';
    case OFFLINE = 'offline';
    case FAILED = 'failed';
    case ACTIVE = 'active';

    /**
     * The run looked and had nothing to do: no agent owns the number, the lead's AI is off. Not a
     * fault, so it is kept apart from FAILED and is never reported.
     */
    case SKIPPED = 'skipped';
}

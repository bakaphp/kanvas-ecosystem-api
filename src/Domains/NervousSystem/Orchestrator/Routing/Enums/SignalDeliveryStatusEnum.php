<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Enums;

enum SignalDeliveryStatusEnum: string
{
    case DELIVERED = 'routed';
    case DUPLICATE = 'duplicate';

    /** No agent to work it: nothing is written, the meeting is only logged. */
    case NO_AGENT = 'skipped';

    case FAILED = 'failed';
}

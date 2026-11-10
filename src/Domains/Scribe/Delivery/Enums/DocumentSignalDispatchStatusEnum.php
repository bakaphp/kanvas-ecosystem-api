<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Enums;

enum DocumentSignalDispatchStatusEnum: string
{
    case PENDING = 'pending';
    case DISPATCHED = 'dispatched';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}

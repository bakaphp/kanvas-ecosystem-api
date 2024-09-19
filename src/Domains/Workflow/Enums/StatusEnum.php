<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum StatusEnum: string
{
    case CONNECTED = 'connected';
    case OFFLINE = 'offline';
    case FAILED = 'failed';
    case ACTIVE = 'active';
}

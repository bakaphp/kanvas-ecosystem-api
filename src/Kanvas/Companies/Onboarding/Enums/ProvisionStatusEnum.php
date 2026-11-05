<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Enums;

enum ProvisionStatusEnum: string
{
    case WAITING = 'waiting';
    case CREATING = 'creating';
    case READY = 'ready';
    case FAILED = 'failed';
}

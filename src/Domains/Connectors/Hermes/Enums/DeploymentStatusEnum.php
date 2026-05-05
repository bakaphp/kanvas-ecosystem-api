<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Enums;

enum DeploymentStatusEnum: string
{
    case PENDING = 'pending';
    case PROVISIONING = 'provisioning';
    case RUNNING = 'running';
    case UPDATING = 'updating';
    case STOPPED = 'stopped';
    case FAILED = 'failed';
    case TERMINATED = 'terminated';
}

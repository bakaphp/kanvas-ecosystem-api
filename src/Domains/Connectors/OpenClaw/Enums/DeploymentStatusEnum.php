<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Enums;

enum DeploymentStatusEnum: string
{
    case PENDING = 'pending';
    case PROVISIONING = 'provisioning';
    case RUNNING = 'running';
    case STOPPED = 'stopped';
    case FAILED = 'failed';
    case TERMINATED = 'terminated';
}

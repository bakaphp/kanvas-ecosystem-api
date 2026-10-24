<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Enums;

enum SetupStatusEnum: string
{
    case PENDING_SETUP = 'pending_setup';
    case PROVISIONING = 'provisioning';
    case READY = 'ready';
    case BLOCKED = 'blocked';
    case FAILED = 'failed';
    case DISCONNECTING = 'disconnecting';
    case DISCONNECTED = 'disconnected';
}

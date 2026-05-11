<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Enums;

/**
 * Canonical deployment lifecycle states shared by all agent runtime providers.
 *
 * Replaces the per-provider copies (OpenClaw\Enums\DeploymentStatusEnum,
 * Hermes\Enums\DeploymentStatusEnum) which were byte-for-byte identical.
 */
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

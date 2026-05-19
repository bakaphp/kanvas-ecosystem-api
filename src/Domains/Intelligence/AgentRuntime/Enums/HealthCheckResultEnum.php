<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

// UNSUPPORTED short-circuits the state machine in BaseCheckHealthAction so runtimes without
// a probe (OpenClaw today) are silent no-ops in the unified cron.
enum HealthCheckResultEnum: string
{
    case OK = 'ok';
    case FAILED = 'failed';
    case UNSUPPORTED = 'unsupported';
}

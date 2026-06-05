<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentDeploymentEventTypeEnum: string
{
    case GATEWAY_DOWN = 'gateway_down';
    case GATEWAY_UP = 'gateway_up';
    case HEALTH_FAIL = 'health_fail';
    case HEALTH_RECOVER = 'health_recover';
    case SESSION_STARTED = 'session_started';
    case AGENT_UNREACHABLE = 'agent_unreachable';
}

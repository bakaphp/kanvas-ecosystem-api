<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

/**
 * Runtime-agnostic agent custom-field keys used by the shared `BaseCheckHealthAction` state
 * machine. The naming mirrors `AgentChannelTokenEnum` — one shared key across runtimes rather
 * than `HERMES_*` / `OPENCLAW_*` parallel sets, because an agent only runs on one runtime at
 * a time and migration re-launches the deployment anyway.
 */
enum AgentRuntimeStateEnum: string
{
    // Result of the most recent runtime health probe ('ok' | 'failed'). Read by BaseCheckHealthAction
    // on the next tick to apply the 2-strike awake_state flip — a single failed probe is tolerated
    // (network blip / SSH reconnect), but two in a row marks the deployment offline.
    case LAST_HEALTH_STATUS = 'AGENT_RUNTIME_LAST_HEALTH_STATUS';
}

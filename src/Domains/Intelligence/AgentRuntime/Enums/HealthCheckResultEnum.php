<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

/**
 * Outcome of a per-deployment health probe. `UNSUPPORTED` lets the unified cron walk every
 * runtime without crashing on providers that don't expose a probe yet — `AbstractAgentRuntimeProvider`
 * returns this by default so OpenClaw (no HTTP API today) becomes a clean no-op.
 *
 * The 2-strike state machine in {@see BaseCheckHealthAction} consumes only the OK/FAILED values;
 * UNSUPPORTED short-circuits before the state machine runs.
 */
enum HealthCheckResultEnum: string
{
    case OK = 'ok';
    case FAILED = 'failed';
    case UNSUPPORTED = 'unsupported';
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * Per-app switches for how a Neuron agent's run itself behaves, as opposed to what it remembers
 * (KnowledgeConfigurationEnum).
 */
enum AgentRunConfigurationEnum: string
{
    case DURABLE_RUNS = 'agent_durable_runs_enabled';
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Hermes\Actions\DispatchAgentDeploymentAction as HermesDispatchAction;
use Kanvas\Connectors\Hermes\Jobs\TerminateAgentJob as HermesTerminateAgentJob;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction as OpenClawDispatchAction;
use Kanvas\Connectors\OpenClaw\Jobs\TerminateAgentJob as OpenClawTerminateAgentJob;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseDispatchAgentDeploymentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use ValueError;

enum AgentProviderEnum: string
{
    case NEURON = 'neuron';
    case LARAVEL = 'laravel';
    case ADK = 'adk';
    case OPENCLAW = 'openclaw';
    case HERMES = 'hermes';

    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): BaseDispatchAgentDeploymentAction {
        return match ($this) {
            self::OPENCLAW => new OpenClawDispatchAction($agent, $machine, $app, $company),
            self::HERMES => new HermesDispatchAction($agent, $machine, $app, $company),
            default => throw new ValueError("Provider [{$this->value}] does not support agent deployment."),
        };
    }

    /**
     * Dispatch the provider-specific terminate job for an existing deployment. Routing
     * mirrors dispatchDeployment() — keeps both directions of the lifecycle in one place,
     * so callers (GraphQL resolvers, CLI commands, internal cleanup) don't repeat the match.
     */
    public function dispatchTermination(AgentDeployment $deployment): void
    {
        match ($this) {
            self::OPENCLAW => OpenClawTerminateAgentJob::dispatch($deployment),
            self::HERMES => HermesTerminateAgentJob::dispatch($deployment),
            default => throw new ValueError("Provider [{$this->value}] does not support termination."),
        };
    }
}

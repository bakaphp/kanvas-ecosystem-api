<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\Actions\BaseDispatchAgentDeploymentAction;
use Kanvas\Connectors\Hermes\Actions\DispatchAgentDeploymentAction as HermesDispatchAction;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction as OpenClawDispatchAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

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
            self::HERMES   => new HermesDispatchAction($agent, $machine, $app, $company),
            default        => throw new \ValueError("Provider [{$this->value}] does not support agent deployment."),
        };
    }
}

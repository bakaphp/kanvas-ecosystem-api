<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Agents\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Hermes\Actions\DispatchAgentDeploymentAction as HermesDispatchAgentDeploymentAction;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction as OpenClawDispatchAgentDeploymentAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class AgentDeploymentMutation
{
    public function launch(mixed $root, array $args): AgentDeployment
    {
        $agent = Agent::getById($args['input']['agent_id']);
        $machine = AgentMachine::getById($args['input']['machine_id']);
        $app = app(Apps::class);
        $company = app(Companies::class);
        $provider = $args['input']['provider'] ?? 'OPENCLAW';

        if ($provider === 'HERMES') {
            return (new HermesDispatchAgentDeploymentAction(
                $agent,
                $machine,
                $app,
                $company
            ))->execute();
        } elseif ($provider === 'OPENCLAW') {
            return (new OpenClawDispatchAgentDeploymentAction(
                $agent,
                $machine,
                $app,
                $company
            ))->execute();
        }

        throw new ValidationException('Invalid agent provider specified.');
    }
}

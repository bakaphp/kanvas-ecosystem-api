<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Agents\Mutations;

use Kanvas\Apps\Models\Apps;
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
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp((int) $args['input']['agent_id'], $company, $app);
        $machine = AgentMachine::getByIdFromCompanyApp((int) $args['input']['machine_id'], $company, $app);
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

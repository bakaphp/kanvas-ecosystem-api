<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Agents\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class AgentDeploymentMutation
{
    public function launch(mixed $root, array $args): AgentDeployment
    {
        $app = app(Apps::class);
        $agent = Agent::getById((int) $args['input']['agent_id'], $app);
        $company = $agent->company;
        $machine = AgentMachine::getByIdFromCompanyApp((int) $args['input']['machine_id'], $company, $app);
        $provider = AgentProviderEnum::from(strtolower($args['input']['provider'] ?? 'openclaw'));

        return $provider->dispatchDeployment($agent, $machine, $app, $company)->execute();
    }
}

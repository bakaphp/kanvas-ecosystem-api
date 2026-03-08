<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\AgentSwarm;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentSwarmAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentSwarmAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Enums\AgentSwarmStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;

class AgentSwarmMutation
{
    public function create(mixed $rootValue, array $request): AgentSwarm
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreateAgentSwarmAction(
            new AgentSwarmData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                description: $input['description'] ?? null,
                status: AgentSwarmStatusEnum::from($input['status'] ?? 'draft'),
                config: $input['config'] ?? null,
                agentIds: $input['agent_ids'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): AgentSwarm
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var AgentSwarm $swarm */
        $swarm = AgentSwarm::getByIdFromCompanyApp(
            (int) $request['id'],
            $company,
            $app
        );

        return new UpdateAgentSwarmAction(
            $swarm,
            new AgentSwarmData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $swarm->name,
                description: $input['description'] ?? $swarm->description,
                status: AgentSwarmStatusEnum::from($input['status'] ?? $swarm->status),
                config: $input['config'] ?? $swarm->config,
                agentIds: $input['agent_ids'] ?? null,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentSwarm $swarm */
        $swarm = AgentSwarm::getByIdFromCompanyApp(
            (int) $request['id'],
            $company,
            $app
        );

        return $swarm->softDelete();
    }

    public function addAgent(mixed $rootValue, array $request): AgentSwarm
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentSwarm $swarm */
        $swarm = AgentSwarm::getByIdFromCompanyApp(
            (int) $request['swarm_id'],
            $company,
            $app
        );

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            (int) $request['agent_id'],
            $company,
            $app
        );

        if (! $swarm->agents()->where('agent_id', $agent->getId())->exists()) {
            $swarm->agents()->attach($agent->getId());
        }

        return $swarm;
    }

    public function removeAgent(mixed $rootValue, array $request): AgentSwarm
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentSwarm $swarm */
        $swarm = AgentSwarm::getByIdFromCompanyApp(
            (int) $request['swarm_id'],
            $company,
            $app
        );

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            (int) $request['agent_id'],
            $company,
            $app
        );

        $swarm->agents()->detach($agent->getId());

        return $swarm;
    }
}

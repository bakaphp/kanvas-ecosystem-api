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
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;

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

        if (! AgentSwarmMember::where('agent_swarm_id', $swarm->getId())
            ->where('agent_id', $agent->getId())
            ->where('is_deleted', 0)
            ->exists()
        ) {
            $memberData = [
                'agent_swarm_id' => $swarm->getId(),
                'agent_id' => $agent->getId(),
                'role' => $request['role'] ?? null,
            ];

            if (isset($request['reports_to_agent_id'])) {
                /** @var AgentSwarmMember $reportsToMember */
                $reportsToMember = AgentSwarmMember::where('agent_swarm_id', $swarm->getId())
                    ->where('agent_id', (int) $request['reports_to_agent_id'])
                    ->where('is_deleted', 0)
                    ->firstOrFail();

                $memberData['parent_id'] = $reportsToMember->getId();
            }

            AgentSwarmMember::create($memberData);
        }

        return $swarm;
    }

    public function updateSwarmMember(mixed $rootValue, array $request): AgentSwarmMember
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentSwarm $swarm */
        $swarm = AgentSwarm::getByIdFromCompanyApp(
            (int) $request['swarm_id'],
            $company,
            $app
        );

        /** @var AgentSwarmMember $member */
        $member = AgentSwarmMember::where('agent_swarm_id', $swarm->getId())
            ->where('agent_id', (int) $request['agent_id'])
            ->where('is_deleted', 0)
            ->firstOrFail();

        if (isset($request['role'])) {
            $member->role = $request['role'];
        }

        if (array_key_exists('reports_to_agent_id', $request)) {
            if ($request['reports_to_agent_id'] === null) {
                $member->parent_id = null;
            } else {
                $reportsToMember = AgentSwarmMember::where('agent_swarm_id', $swarm->getId())
                    ->where('agent_id', (int) $request['reports_to_agent_id'])
                    ->where('is_deleted', 0)
                    ->firstOrFail();

                $member->parent_id = $reportsToMember->getId();
            }
        }

        $member->saveOrFail();

        return $member;
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

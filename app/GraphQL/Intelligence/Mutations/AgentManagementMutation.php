<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentDTO;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;

class AgentManagementMutation
{
    public function create(mixed $root, array $req): Agent
    {
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agentType = AgentTypeModel::getById($input['agent_type_id'], app: $app);
        $agentModel = isset($input['agent_model_id']) ? AgentModel::getById($input['agent_model_id'], app: $app) : null;
        $task = isset($input['company_task_list_id']) ? TaskList::getById($input['company_task_list_id'], app: $app) : null;
        $parentAgent = isset($input['parent_agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $input['parent_agent_id'], $company, $app)
            : null;

        $input = $this->mapRoleToFields($input);

        $agentDTO = new AgentDTO(
            app: $app,
            company: $company,
            user: auth()->user(),
            agentModel: $agentModel,
            agentType: $agentType,
            name: $input['name'],
            role: $input['role'],
            is_active: $input['is_active'],
            description: $input['description'],
            config: $input['config'],
            task: $task,
            communicationChannel: $input['communication_channels'] ?? [],
            soul: $input['soul'] ?? null,
            instructions: $input['instructions'] ?? null,
            outputFormat: $input['output_format'] ?? null,
            identity: $input['identity'] ?? null,
            userContext: $input['user_context'] ?? null,
            toolsConfig: $input['tools_config'] ?? null,
            parentAgent: $parentAgent,
        );

        $agent = new CreateAgentAction($agentDTO)->execute();

        if (! empty($input['swarm_ids'])) {
            $this->syncSwarms($agent, $input['swarm_ids'], $company, $app);
        }

        return $agent;
    }

    public function update(mixed $root, array $req): Agent
    {
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp((int) $req['id'], $company, $app);
        $agentType = AgentTypeModel::getById($input['agent_type_id'], app: $app);
        $agentModel = isset($input['agent_model_id']) ? AgentModel::getById($input['agent_model_id'], app: $app) : null;
        $task = isset($input['company_task_list_id']) ? TaskList::getById($input['company_task_list_id'], app: $app) : null;
        $parentAgent = isset($input['parent_agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $input['parent_agent_id'], $company, $app)
            : null;

        $input = $this->mapRoleToFields($input);

        $agentDTO = new AgentDTO(
            app: $app,
            company: $company,
            user: auth()->user(),
            agentType: $agentType,
            agentModel: $agentModel,
            name: $input['name'],
            role: $input['role'],
            is_active: $input['is_active'],
            description: $input['description'],
            config: $input['config'],
            task: $task,
            communicationChannel: $input['communication_channels'] ?? [],
            soul: $input['soul'] ?? null,
            instructions: $input['instructions'] ?? null,
            outputFormat: $input['output_format'] ?? null,
            identity: $input['identity'] ?? null,
            userContext: $input['user_context'] ?? null,
            toolsConfig: $input['tools_config'] ?? null,
            parentAgent: $parentAgent,
        );

        $agent = new UpdateAgentAction($agentDTO, $agent)->execute();

        if (isset($input['swarm_ids'])) {
            $this->syncSwarms($agent, $input['swarm_ids'], $company, $app);
        }

        return $agent;
    }

    public function delete(mixed $root, array $req): bool
    {
        $agent = Agent::getByIdFromCompanyApp(
            id: $req['id'],
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany()
        );

        return (bool) $agent->delete();
    }

    /**
     * When role is provided and soul/instructions are not explicitly set,
     * auto-populate them from role['background'] and role['steps'] for backward compatibility.
     */
    private function mapRoleToFields(array $input): array
    {
        $role = $input['role'] ?? null;

        if (! is_array($role)) {
            return $input;
        }

        if (empty($input['soul']) && ! empty($role['background'])) {
            $background = $role['background'];
            $input['soul'] = is_array($background) ? implode("\n", $background) : (string) $background;
        }

        if (empty($input['instructions']) && ! empty($role['steps'])) {
            $steps = $role['steps'];
            $input['instructions'] = is_array($steps) ? implode("\n", $steps) : (string) $steps;
        }

        return $input;
    }

    /**
     * @param array<int, string> $swarmIds
     */
    private function syncSwarms(
        Agent $agent,
        array $swarmIds,
        CompanyInterface $company,
        AppInterface $app
    ): void {
        $agent->swarms()->detach();

        foreach ($swarmIds as $swarmId) {
            AgentSwarm::getByIdFromCompanyApp(
                (int) $swarmId,
                $company,
                $app
            );

            $agent->swarms()->attach((int) $swarmId);
        }
    }
}

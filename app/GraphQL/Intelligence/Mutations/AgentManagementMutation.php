<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentDTO;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
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

        return new CreateAgentAction($agentDTO)->execute();
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

        return new UpdateAgentAction($agentDTO, $agent)->execute();
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
}

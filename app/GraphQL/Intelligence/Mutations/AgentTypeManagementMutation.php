<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentTypeAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentTypeAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentType as AgentTypeDTO;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;

class AgentTypeManagementMutation
{
    public function create(mixed $root, array $req): AgentTypeModel
    {
        $input = $req['input'] ?? [];
        $agentTypeDTO = new AgentTypeDTO(
            app: app(Apps::class),
            name: $input['name'],
            description: $input['description'],
            config: $input['config'],
            role: $input['role'],
            is_active: $input['is_active'],
            is_published: $input['is_published'],
            is_multi_agent: $input['is_multi_agent'],
            multi_agent_list: $input['multi_agent_list']
        );
        $action = new CreateAgentTypeAction($agentTypeDTO);

        return $action->execute();
    }

    public function update(mixed $root, array $req): AgentTypeModel
    {
        $input = $req['input'] ?? [];
        $agentTypeModel = AgentTypeModel::findOrFail($req['id']);
        $agentTypeDTO = new AgentTypeDTO(
            app: app(Apps::class),
            name: $input['name'],
            description: $input['description'],
            config: $input['config'],
            role: $input['role'],
            is_active: $input['is_active'],
            is_published: $input['is_published'],
            is_multi_agent: $input['is_multi_agent'],
            multi_agent_list: $input['multi_agent_list']
        );
        $action = new UpdateAgentTypeAction($agentTypeDTO, $agentTypeModel);

        return $action->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $agentTypeModel = AgentTypeModel::getById($req['id'], app(Apps::class));

        return $agentTypeModel->delete();
    }
}

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
            description: $input['description'] ?? null,
            provider: $input['provider'] ?? null,
            handler: $input['handler'] ?? null,
            config: $input['config'] ?? null,
            role: $input['role'] ?? null,
            soul: $input['soul'] ?? null,
            instructions: $input['instructions'] ?? null,
            output_format: $input['output_format'] ?? null,
            is_active: $input['is_active'] ?? true,
            is_published: $input['is_published'] ?? false,
            is_multi_agent: $input['is_multi_agent'] ?? false,
            is_default: $input['is_default'] ?? false,
            multi_agent_list: $input['multi_agent_list'] ?? null,
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
            description: $input['description'] ?? null,
            provider: $input['provider'] ?? $agentTypeModel->provider,
            handler: $input['handler'] ?? $agentTypeModel->handler,
            config: $input['config'] ?? $agentTypeModel->config,
            role: $input['role'] ?? $agentTypeModel->role,
            soul: $input['soul'] ?? $agentTypeModel->soul,
            instructions: $input['instructions'] ?? $agentTypeModel->instructions,
            output_format: $input['output_format'] ?? $agentTypeModel->output_format,
            is_active: $input['is_active'] ?? $agentTypeModel->is_active,
            is_published: $input['is_published'] ?? $agentTypeModel->is_published,
            is_multi_agent: $input['is_multi_agent'] ?? $agentTypeModel->is_multi_agent,
            is_default: $input['is_default'] ?? $agentTypeModel->is_default,
            multi_agent_list: $input['multi_agent_list'] ?? null,
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

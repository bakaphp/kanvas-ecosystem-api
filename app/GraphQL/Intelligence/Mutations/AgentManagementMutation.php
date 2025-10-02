<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentDTO;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\AgentMonitoring;

class AgentManagementMutation
{
    public function create(mixed $root, array $req): Agent
    {
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $agentType = AgentTypeModel::getById($input['agent_type_id'], app: $app);
        $agentModel = isset($input['agent_model_id']) ? AgentModel::getById($input['agent_model_id'], app: $app) : null;
        $task = isset($input['company_task_list_id']) ? TaskList::getById($input['company_task_list_id'], app: $app) : null;
        $agentDTO = new AgentDTO(
            app: $app,
            company: auth()->user()->getCurrentCompany(),
            user: auth()->user(),
            agentModel: $agentModel,
            agentType: $agentType,
            name: $input['name'],
            role: $input['role'],
            is_active: $input['is_active'],
            description: $input['description'],
            config: $input['config'],
            task: $task,
            communicationChannel: $input['communication_channels'] ?? []
        );

        return new CreateAgentAction($agentDTO)->execute();
    }

    public function update(mixed $root, array $req): Agent
    {
        $input = $req['input'] ?? [];
        $agent = Agent::findOrFail($req['id']);
        $app = app(Apps::class);
        $agentType = AgentTypeModel::getById($input['agent_type_id'], app: $app);
        $agentModel = isset($input['agent_model_id']) ? AgentModel::getById($input['agent_model_id'], app: $app) : null;
        $task = isset($input['company_task_list_id']) ? TaskList::getById($input['company_task_list_id'], app: $app) : null;
        $agentDTO = new AgentDTO(
            app: $app,
            company: auth()->user()->getCurrentCompany(),
            user: auth()->user(),
            agentType: $agentType,
            agentModel: $agentModel,
            name: $input['name'],
            role: $input['role'],
            is_active: $input['is_active'],
            description: $input['description'],
            config: $input['config'],
            task: $task,
            communicationChannel: $input['communication_channels'] ?? []
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

    public function chat(mixed $root, array $req): string
    {
        $req = $req['input'] ?? [];
        $app = app(Apps::class);
        $agent = Agent::getByIdFromCompanyApp(
            id: $req['agent_id'],
            app: $app,
            company: auth()->user()->getCurrentCompany()
        );

        $useInspector = $app->get('inspector-key') !== null;

        $currentAgent = new $agent->type->handler();
        //$currentAgent = $this->agent;

        $currentAgent->setConfiguration(
            $agent,
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($app->get('inspector-key'))
            );
            $currentAgent->observe(
                new AgentMonitoring($inspector)
            );
        }

        $responseContent = $currentAgent instanceof ADKAgent ?
        $currentAgent->chatSimple(
            $app,
            $agent->company,
            (string) auth()->user()->getId(),
            $req['session_id'],
            $req['message']
        ) : $currentAgent->chat(new UserMessage($req['message']));

        $responseText = ChatHelper::extractTextFromResponse($responseContent->getContent());

        return $responseText;
    }
}

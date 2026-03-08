<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Support\Str;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentDTO;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\InspectorObserver;

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

    public function chat(mixed $root, array $req): string
    {
        $req = $req['input'] ?? [];
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp(
            id: $req['agent_id'],
            app: $app,
            company: $company
        );

        if ($agent->type->handler === OpenClawAgentHandler::class) {
            $handler = new OpenClawAgentHandler();
            $handler->setAgent($agent);

            return $handler->chat($req['message']);
        }

        $useInspector = $app->get('inspector-key') !== null;

        $currentAgent = new $agent->type->handler();
        $sessionEntity = Session::fromApp($app)->fromCompany($company)->where('uuid', (string) $req['session_id'])->first()?->entity();

        $currentAgent->setConfiguration(
            $agent,
            $sessionEntity
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($app->get('inspector-key'))
            );
            $currentAgent->observe(
                new InspectorObserver($inspector)
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

        return ChatHelper::extractTextFromResponse($responseContent->getContent());
    }

    public function createSession(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $req['input'] ?? [];
        $agent = Agent::getByIdFromCompanyApp(
            id: $input['agent_id'],
            app: $app,
            company: $company
        );

        $lead = Lead::getByIdFromCompanyApp(
            id: $input['lead_id'],
            app: $app,
            company: $company
        );

        $channelName = 'Manual Channel for Lead ' . $lead->getId();
        $slug = Str::simpleSlug($channelName);

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $user,
                name: $channelName,
                description: 'Channel for lead ' . $lead->getId(),
                entity_id: $lead->getId(),
                entity_namespace: Lead::class,
                slug: $slug,
            )
        )->execute();

        $chatSession = new CreateSessionAction(
            DataTransferObjectSession::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => $input['canal_id'],
                'user' => [
                    'name' => $lead->people->getName(),
                    'id' => $lead->people->getId(),
                    'email' => $lead->people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();

        return $chatSession->uuid;
    }
}

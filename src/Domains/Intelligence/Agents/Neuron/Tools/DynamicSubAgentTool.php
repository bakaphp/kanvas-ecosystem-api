<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Neuron\Factories\NeuronAgentFactory;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Exposes a database-backed Neuron agent as a callable Neuron tool.
 *
 * The nervous-system tool row carries agents_id instead of a PHP handler.
 * This adapter resolves that agent at runtime and forwards the parent turn
 * context so the child sees the same entity, lead, user, and session.
 */
class DynamicSubAgentTool extends Tool
{
    public function __construct(
        private readonly AgentRecord $agentRecord,
        private readonly ?Model $entity,
        private readonly Users $user,
        private readonly ?Session $session = null,
        private readonly ?Lead $currentLead = null,
        private readonly ?string $threadId = null,
    ) {
        parent::__construct(
            Str::snake($this->agentRecord->name),
            $this->agentRecord->soul
                ?? $this->agentRecord->description
                ?? $this->agentRecord->name,
        );

        $this->setMaxRuns(3);
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'request',
                type: PropertyType::STRING,
                description: 'The complete task and relevant context for the specialist sub-agent.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $request): string
    {
        $agent = NeuronAgentFactory::fromAgent(
            agent: $this->agentRecord,
            entity: $this->entity,
            user: $this->user,
        );

        $agent->setSession($this->session);
        $agent->setCurrentLead($this->currentLead);

        if ($this->threadId !== null && $this->threadId !== '') {
            $agent->setThreadId($this->threadId . ':sub-agent:' . $this->agentRecord->getId());
        }

        $response = $agent->chat(new UserMessage($request));
        $message = $response instanceof AgentHandler
            ? $response->getMessage()
            : $response;

        return ChatHelper::extractTextFromResponse($message->getContent() ?? '');
    }
}

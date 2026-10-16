<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;

class FakeAgentHandler
{
    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
    ): void {
    }

    public function setThreadId(string $threadId): static
    {
        return $this;
    }

    public function chat(UserMessage $message): AgentState
    {
        return new AgentState()->setResponse(
            new ProviderResponse(message: new AssistantMessage('This is a fake agent response.')),
        );
    }
}

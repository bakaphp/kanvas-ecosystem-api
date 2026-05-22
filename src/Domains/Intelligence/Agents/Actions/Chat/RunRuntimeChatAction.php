<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;

class RunRuntimeChatAction
{
    /**
     * @param list<string> $images URLs to forward as multimodal image items.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Users $user,
        protected readonly array $images = [],
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        $deployment = $this->agent->activeDeployment;
        $provider = $deployment instanceof AgentDeployment
            ? AgentRuntimeProviderFactory::forDeployment($deployment)
            : AgentRuntimeProviderFactory::forAgent($this->agent);

        $response = $provider->chat(
            agent: $this->agent,
            message: $this->message,
            sessionKey: $sessionId !== '' ? $sessionId : null,
            images: $this->images,
        );

        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: $provider::class,
            userMessage: $this->message,
            assistantResponse: $response,
        );

        return $response;
    }
}

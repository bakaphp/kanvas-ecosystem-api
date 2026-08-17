<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;

class RunRuntimeChatAction
{
    /**
     * @param list<string> $images URLs to forward as multimodal image items.
     * @param list<object> $additionalTools Injected for this turn only. Machine runtimes ignore
     *        them; hosted runtimes bridge them back in-process.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Users $user,
        protected readonly array $images = [],
        protected readonly array $additionalTools = [],
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        $provider = AgentRuntimeProviderFactory::forRunningAgent($this->agent);

        $response = $provider->chat(
            agent: $this->agent,
            message: $this->message,
            sessionKey: $sessionId !== '' ? $sessionId : null,
            images: $this->images,
            additionalTools: $this->additionalTools,
        );

        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: $provider::class,
            userMessage: $this->message,
            assistantResponse: $response,
            agentId: $this->agent->getId(),
        );

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;

class RunOpenClawChatAction
{
    /**
     * @param list<string> $images URLs to forward as multimodal `input_image` items.
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

        $handler = new OpenClawAgentHandler();
        $handler->setAgent($this->agent);

        $response = $handler->chat(
            $this->message,
            $sessionId !== '' ? $sessionId : null,
            $this->images,
        );

        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: OpenClawAgentHandler::class,
            userMessage: $this->message,
            assistantResponse: $response,
        );

        return $response;
    }
}

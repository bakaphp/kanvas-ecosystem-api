<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\UserMessage;

class RunNeuronChatAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly mixed $handler,
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        $responseContent = $this->handler instanceof ADKAgent
            ? $this->handler->chatSimple(
                $this->app,
                $this->agent->company,
                (string) $this->user->getId(),
                $sessionId,
                $this->message
            )
            : $this->handler->chat(new UserMessage($this->message));

        if ($this->handler instanceof ADKAgent) {
            $response = $responseContent->getContent();
        } elseif ($responseContent instanceof AgentHandler) {
            $response = ChatHelper::extractTextFromResponse($responseContent->getMessage()->getContent());
        } else {
            $response = ChatHelper::extractTextFromResponse($responseContent->getContent());
        }

        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: get_class($this->handler),
            userMessage: $this->message,
            assistantResponse: $response,
        );

        return $response;
    }
}

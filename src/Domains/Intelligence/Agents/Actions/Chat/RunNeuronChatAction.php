<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\InspectorObserver;

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

        if ($this->app->get('inspector-key') !== null) {
            $this->handler->observe(
                new InspectorObserver(
                    new Inspector(new Configuration($this->app->get('inspector-key')))
                )
            );
        }

        $responseContent = $this->handler instanceof ADKAgent
            ? $this->handler->chatSimple(
                $this->app,
                $this->agent->company,
                (string) $this->user->getId(),
                $sessionId,
                $this->message
            )
            : $this->handler->chat(new UserMessage($this->message));

        $response = $this->handler instanceof ADKAgent
            ? $responseContent->getContent()
            : ChatHelper::extractTextFromResponse($responseContent->getContent());

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

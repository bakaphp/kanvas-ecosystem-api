<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Concerns\RemembersConversations;

class RunLaravelAgentChatAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly KanvasLaravelAgent $handler,
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';
        $sessionEntity = $this->session?->entity();
        $usesMemory = in_array(RemembersConversations::class, class_uses_recursive($this->handler));

        if ($usesMemory) {
            $sessionId !== ''
                ? $this->handler->continueLastConversation($this->user)
                : $this->handler->forUser($this->user);
        }

        $response = $this->handler->promptWithConfig($this->message)->text;

        if ($sessionEntity !== null) {
            AgentHistory::create([
                'agent_id' => $this->agent->getId(),
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'entity_namespace' => get_class($sessionEntity),
                'entity_id' => $sessionEntity->getId(),
                'context' => $sessionId,
                'input' => ['role' => 'user', 'content' => $this->message],
                'output' => ['role' => 'assistant', 'content' => $response],
            ]);
        }

        if (! $usesMemory) {
            new KanvasConversationStore()->logTurn(
                userId: $this->user->getId(),
                sessionId: $sessionId,
                agentClass: get_class($this->handler),
                userMessage: $this->message,
                assistantResponse: $response,
            );
        }

        return $response;
    }
}

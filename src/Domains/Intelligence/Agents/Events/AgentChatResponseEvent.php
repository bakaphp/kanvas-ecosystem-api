<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

class AgentChatResponseEvent implements ShouldBroadcastNow
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected Agent $agent,
        protected string $sessionId,
        protected string $message,
        protected string $response,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agent->getId(),
            'agent_name' => $this->agent->name,
            'session_id' => $this->sessionId,
            'message' => $this->message,
            'response' => $this->response,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(
            'agent-chat-' . $this->agent->apps_id . '-' . $this->agent->companies_id . '-' . $this->sessionId
        );
    }

    public function broadcastAs(): string
    {
        return 'agent.chat.response';
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Agents\Helpers\AgentChatBroadcastChannel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

/**
 * A queued turn that died writes no message, so this is the only signal the chat is over —
 * without it the client spins forever. Carries no exception text; that is not client-facing.
 */
class AgentChatFailedEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected Agent $agent,
        protected string $sessionId,
    ) {
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agent->getId(),
            'agent_name' => $this->agent->name,
            'session_id' => $this->sessionId,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(AgentChatBroadcastChannel::nameFor($this->agent, $this->sessionId));
    }

    public function broadcastAs(): string
    {
        return AgentChatBroadcastChannel::FAILED_EVENT;
    }
}

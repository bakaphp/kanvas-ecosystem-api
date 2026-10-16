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
 * A signal about a queued turn that wrote no message: the only thing the chat gets, so the client
 * can stop waiting. Carries no exception text; that is not client-facing. Subclasses differ only by
 * the event name, and stay separate classes so a listener or a test can tell a stop from a failure.
 */
abstract class AgentChatSessionEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected Agent $agent,
        protected string $sessionId,
    ) {
    }

    abstract public function broadcastAs(): string;

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
}

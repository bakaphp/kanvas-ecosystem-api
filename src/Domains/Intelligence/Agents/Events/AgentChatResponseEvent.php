<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Events;

use Baka\Traits\LimitsBroadcastPayload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Agents\Helpers\AgentChatBroadcastChannel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Override;

class AgentChatResponseEvent implements ShouldBroadcastNow
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;
    use LimitsBroadcastPayload;

    /** @param Message|null $replyMessage Null on the connector path, which persists after the kernel returns. */
    public function __construct(
        protected Agent $agent,
        protected string $sessionId,
        protected string $message,
        protected string $response,
        protected ?Message $replyMessage = null,
    ) {
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * `message_id` stays outside the capped set so it is always present: limitBroadcastPayloadSet
     * NULLS `response` (it does not truncate) once the payload passes Pusher's ~10KB ceiling, which
     * any table-shaped answer does. Clients render `response` if present, else fetch by `message_id`.
     */
    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agent->getId(),
            'agent_name' => $this->agent->name,
            'session_id' => $this->sessionId,
            'message_id' => $this->replyMessage?->getId(),
            ...$this->limitBroadcastPayloadSet([
                'message' => $this->message,
                'response' => $this->response,
            ]),
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(AgentChatBroadcastChannel::nameFor($this->agent, $this->sessionId));
    }

    public function broadcastAs(): string
    {
        return AgentChatBroadcastChannel::RESPONSE_EVENT;
    }
}

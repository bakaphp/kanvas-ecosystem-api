<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Override;

class LedgerEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Event $event,
    ) {
    }

    /**
     * Channels (frontend pairs must match exactly):
     *   - company-{cid}-app-{aid}-ledger
     *   - app-{aid}-ledger
     *   - company-{cid}-app-{aid}-agent-{actor_id}-ledger  (only when actor_type='Agent')
     *
     * @return array<int, Channel>
     */
    #[Override]
    public function broadcastOn(): array
    {
        $channels = [
            new Channel(
                'company-' . $this->event->companies_id
                . '-app-' . $this->event->apps_id
                . '-ledger'
            ),
            new Channel('app-' . $this->event->apps_id . '-ledger'),
        ];

        if ($this->event->actor_type === 'Agent' && $this->event->actor_id !== null) {
            $channels[] = new Channel(
                'company-' . $this->event->companies_id
                . '-app-' . $this->event->apps_id
                . '-agent-' . $this->event->actor_id
                . '-ledger'
            );
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ledger.event.appended';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->event->id,
            'uuid' => $this->event->uuid,
            'apps_id' => $this->event->apps_id,
            'companies_id' => $this->event->companies_id,
            'source_domain' => $this->event->source_domain,
            'source_entity_type' => $this->event->source_entity_type,
            'source_entity_id' => $this->event->source_entity_id,
            'event_type' => $this->event->event_type,
            'actor_type' => $this->event->actor_type,
            'actor_id' => $this->event->actor_id,
            'status' => $this->event->status,
            'duration_ms' => $this->event->duration_ms,
            'correlation_id' => $this->event->correlation_id,
            'causation_id' => $this->event->causation_id,
            'occurred_at' => $this->event->occurred_at->toIso8601String(),
        ];
    }
}

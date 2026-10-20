<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Events;

use Baka\Support\Str;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

/**
 * Scalars only, no Eloquent model: the event crosses the `broadcasts` queue, where a retained model
 * either serializes whole (no SerializesModels) or is re-queried against a row that may be gone.
 *
 * `action` is the engagement slug — the string the client asked to start, variant suffix included.
 * The sibling events `engagement.status` and `engagement.updated` put a display *name* under the
 * same key, so do not share a formatter across the three.
 *
 * Fires per engagement row, not per tree: a parent's children each emit one. Clients that need tree
 * state re-read `Engagement.is_complete`, and dedupe on (leadId, messageId), never engagementId.
 */
class EngagementCompletedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly int $leadId,
        public readonly string $leadUuid,
        public readonly int $engagementId,
        public readonly string $action,
        public readonly int $companyActionId,
        public readonly int $messageId,
        public readonly string $completedAt
    ) {
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(Str::sanitizeChannelName('engagement-completed-lead-' . $this->leadUuid));
    }

    public function broadcastAs(): string
    {
        return 'engagement.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'leadId' => $this->leadId,
            'leadUuid' => $this->leadUuid,
            'engagementId' => $this->engagementId,
            'action' => $this->action,
            'companyActionId' => $this->companyActionId,
            'messageId' => $this->messageId,
            'completedAt' => $this->completedAt,
        ];
    }
}

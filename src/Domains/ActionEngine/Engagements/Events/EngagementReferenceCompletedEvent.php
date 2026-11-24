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
 * EngagementCompletedEvent for an engagement on any non-lead reference (a quote accepted, an invoice
 * paid). Same scalars-only rule; keyed by the reference alias + uuid instead of a lead, so a document
 * page can listen on its own channel and flip to "paid" / "accepted" live.
 */
class EngagementReferenceCompletedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $entityUuid,
        public readonly int $engagementId,
        public readonly string $action,
        public readonly string $status,
        public readonly int $messageId,
        public readonly string $completedAt
    ) {
    }

    public static function channelName(string $entityType, string $entityUuid): string
    {
        return Str::sanitizeChannelName('engagement-completed-' . $entityType . '-' . $entityUuid);
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(self::channelName($this->entityType, $this->entityUuid));
    }

    public function broadcastAs(): string
    {
        return 'engagement.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'entityUuid' => $this->entityUuid,
            'engagementId' => $this->engagementId,
            'action' => $this->action,
            'status' => $this->status,
            'messageId' => $this->messageId,
            'completedAt' => $this->completedAt,
        ];
    }
}

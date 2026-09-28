<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

/**
 * Carries no entries on purpose: neither the queue nor Pusher delivers in order, so clients re-read
 * the `checklist.generate.pdf` custom field instead of reconciling snapshots.
 */
class ChecklistGeneratePdfEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(public string $leadUuid)
    {
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('checklist-generate-pdf-lead-' . $this->leadUuid);
    }

    public function broadcastAs(): string
    {
        return 'checklist.generate.pdf';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}

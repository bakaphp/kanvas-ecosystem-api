<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Events;

use Baka\Support\Str;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

/**
 * A bare notification: the checklist PDF state on this lead changed, go read it.
 *
 * Carrying the entries instead would force the client to reconcile snapshots that neither the queue
 * nor Pusher delivers in order. Re-reading the `checklist.generate.pdf` custom field is always the
 * current truth, and reuses the same read the page already does on load.
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
        return new Channel(Str::sanitizeChannelName('checklist-generate-pdf-lead-' . $this->leadUuid));
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

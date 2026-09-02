<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Events;

use Baka\Support\Str;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

class ChecklistGeneratePdfEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    /**
     * Primitives only, no models and no SerializesModels: the payload is the snapshot as it stood at
     * write time. On success the entry — often the whole custom field — is already gone by the time
     * this job runs, so re-reading the Lead would publish a later state and erase the transition.
     *
     * @param array<int, array{action_id: int, company_action_id: int, task_id: int, status: string}> $entries
     */
    public function __construct(
        public int $leadId,
        public string $leadUuid,
        public array $entries
    ) {
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

    /**
     * `items` is a full snapshot rather than a delta because neither the queue nor Pusher guarantees
     * ordering — the client replaces its list on every event instead of merging.
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'lead_uuid' => $this->leadUuid,
            'items' => $this->entries,
        ];
    }
}

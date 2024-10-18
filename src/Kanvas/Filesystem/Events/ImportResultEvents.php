<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ImportResultEvents implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected array $result
    ) {
    }

    public function broadcastWith(): array
    {
        $taskEngagementItem = TaskEngagementItemRepository::getLeadsTaskItems(
            Lead::getById($this->taskEngagementItem->leadId)
        )->get();

        return [
            'lead_id' => $this->taskEngagementItem->leadId,
            'task_list_item_id' => $this->taskEngagementItem->taskListItemId,
            //'tasks' => $taskEngagementItem->task->toArray(),
            //'status' => $taskEngagementItem->status,
        ];
    }

    public function broadcastOn(): Channel
    {
        return new Channel('lead-tasks-' . $this->taskEngagementItem->leadId);
    }

    public function broadcastAs(): string
    {
        return 'lead.tasks';
    }
}

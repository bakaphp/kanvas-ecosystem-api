<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Repositories\TaskEngagementItemRepository;
use Kanvas\Guild\Leads\Models\Lead;

class TaskEngagementItemEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected TaskEngagementItem $taskEngagementItem
    ) {
    }

    public function broadcastWith(): array
    {
        return TaskEngagementItemRepository::getLeadsTaskItems(
            Lead::getById($this->taskEngagementItem->leadId)
        )->get()?->toArray();
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

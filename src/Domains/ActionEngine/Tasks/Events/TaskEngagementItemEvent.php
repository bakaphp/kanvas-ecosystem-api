<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Leads\Models\Lead;
use stdClass;

class TaskEngagementItemEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    // use SerializesModels;
    //use SerializesModels;

    public function __construct(
        protected stdClass $leadTaskInfo
    ) {
    }

    public function broadcastWith()
    {
        return [
            'lead_id' => $this->leadTaskInfo->lead_id,
            'task_list_item_id' => $this->leadTaskInfo->task_list_item_id,
            // other necessary data
        ];
    }

    public function broadcastOn(): Channel
    {
        return new Channel('lead-tasks-' . $this->leadTaskInfo->lead_id);
    }
}

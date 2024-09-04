<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Observers;

use Kanvas\ActionEngine\Tasks\Events\TaskEngagementItemEvent;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Nuwave\Lighthouse\Execution\Utils\Subscription;
use stdClass;

class TaskEngagementItemObserver
{
    public function saved(TaskEngagementItem $taskEngagementItem): void
    {
        // broadcast graphql subscription
        //Subscription::broadcast('leadUpdate', $taskEngagementItem->lead, true);
        $leadInfo = new stdClass();
        $leadInfo->lead_id = $taskEngagementItem->lead_id;
        $leadInfo->task_list_item_id = $taskEngagementItem->task_list_item_id;
        
        TaskEngagementItemEvent::dispatch($leadInfo);
    }
}

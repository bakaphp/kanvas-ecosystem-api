<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Observers;

use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskEngagementItem as DataTransferObjectTaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Events\TaskEngagementItemEvent;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class TaskEngagementItemObserver
{
    public function saved(TaskEngagementItem $taskEngagementItem): void
    {
        // broadcast graphql subscription
        Subscription::broadcast('leadUpdate', $taskEngagementItem->lead, true);

        TaskEngagementItemEvent::dispatch(DataTransferObjectTaskEngagementItem::from([
            'leadId' => $taskEngagementItem->lead_id,
            'taskListItemId' => $taskEngagementItem->task_list_item_id,
        ]));
    }
}

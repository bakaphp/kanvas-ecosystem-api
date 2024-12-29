<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\CheckList\Observers;

use Kanvas\ActionEngine\CheckList\DataTransferObject\TaskEngagementItem as DataTransferObjectTaskEngagementItem;
use Kanvas\ActionEngine\CheckList\Events\TaskEngagementItemEvent;
use Kanvas\ActionEngine\CheckList\Models\TaskEngagementItem;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class TaskEngagementItemObserver
{
    public function saved(TaskEngagementItem $taskEngagementItem): void
    {
        // broadcast graphql subscription
        Subscription::broadcast('leadUpdate', $taskEngagementItem->lead, true);

        TaskEngagementItemEvent::dispatch(
            DataTransferObjectTaskEngagementItem::from([
            'leadId' => $taskEngagementItem->lead_id,
            'taskListItemId' => $taskEngagementItem->task_list_item_id,
        ])
        );

        $taskEngagementItem->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $taskEngagementItem->item->app,
                'company' => $taskEngagementItem->item->company,
            ]
        );
    }
}

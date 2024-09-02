<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Observers;

use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class TaskListItemObserver
{
    public function saved(TaskListItem $taskListItem): void
    {
        // broadcast graphql subscription
        Subscription::broadcast('leadTaskItemUpdated', $taskListItem, true);
    }
}

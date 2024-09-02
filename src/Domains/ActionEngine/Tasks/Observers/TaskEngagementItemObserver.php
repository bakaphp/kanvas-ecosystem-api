<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Observers;

use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class TaskEngagementItemObserver
{
    public function saved(TaskEngagementItem $taskEngagementItem): void
    {
        // broadcast graphql subscription
        Subscription::broadcast('leadTaskUpdated', $taskEngagementItem->item, true);
    }
}

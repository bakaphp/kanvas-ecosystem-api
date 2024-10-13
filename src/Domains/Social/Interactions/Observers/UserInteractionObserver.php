<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UserInteractionObserver
{
    public function created(UsersInteractions $userInteraction): void
    {
        $userInteraction->fireWorkflow(WorkflowEnum::CREATED->value, true, ['app' => $userInteraction->app]);
    }

    public function updated(UsersInteractions $userInteraction): void
    {
        $userInteraction->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $userInteraction->app]);
    }
}

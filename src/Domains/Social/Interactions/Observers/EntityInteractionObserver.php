<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Observers;

use Kanvas\Social\Interactions\Models\EntityInteractions;
use Kanvas\Workflow\Enums\WorkflowEnum;

class EntityInteractionObserver
{
    public function created(EntityInteractions $entityInteraction): void
    {
        $entityInteraction->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            ['app' => $entityInteraction->interaction?->app]
        );
    }

    public function updated(EntityInteractions $entityInteraction): void
    {
        $entityInteraction->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            ['app' => $entityInteraction->interaction?->app]
        );
    }
}

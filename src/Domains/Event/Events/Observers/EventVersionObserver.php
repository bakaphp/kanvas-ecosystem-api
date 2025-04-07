<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Workflow\Enums\WorkflowEnum;

class EventVersionObserver
{
    public function created(EventVersion $eventVersion): void
    {
        $eventVersion->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                'app' => $eventVersion->app,
            ]
        );
    }

    public function updated(EventVersion $eventVersion): void
    {
        $eventVersion->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $eventVersion->app,
            ]
        );
    }
}

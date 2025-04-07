<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Kanvas\Event\Events\Models\Event;
use Kanvas\Workflow\Enums\WorkflowEnum;

class EventObserver
{
    public function created(Event $event): void
    {
        $event->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                'app' => $event->app,
            ]
        );
    }

    public function updated(Event $event): void
    {
        $event->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $event->app,
            ]
        );
    }
}
